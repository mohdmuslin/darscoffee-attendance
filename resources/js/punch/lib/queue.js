import { punchApi } from '../services/api';

/**
 * Offline punch queue.
 *
 * A kitchen has no signal. Without this, someone clocking out at 15:00 either loses the punch
 * or stands there retrying — and the expensive failure is the one that looks like success: the
 * employee walks away believing they clocked out while the system has nothing.
 *
 * WHAT IS STORED, AND WHAT IS NOT
 *
 * Only the action and the moment it happened. NOT the photograph: a base64 image is hundreds of
 * kilobytes, localStorage caps out around 5MB, and filling it would break the session token
 * stored alongside. So a queued punch carries no photo, which is a real limitation and is
 * surfaced rather than hidden — see `hasPhotoDependentActions`.
 *
 * THE TIME IS CAPTURED AT THE MOMENT OF THE PUNCH, not when the queue drains. Queue a 15:00
 * clock-out and sync at 20:00, and the punch must record 15:00. Sending the sync time instead
 * would make the feature worse than useless: it would invent five hours of work.
 *
 * WHY localStorage AND NOT IndexedDB
 *
 * The session token already lives in localStorage. A queue in a second storage system means two
 * things to clear and two things to get out of step, and a stale queue surviving a logout is
 * exactly how one person's punch ends up attributed to the next person on a shared phone.
 */
const QUEUE_KEY = 'dars.punch_queue';

/** Entries older than the server's backdating window are dropped rather than retried for ever. */
const MAX_QUEUE_AGE_HOURS = 24;

/** A cap, so a phone offline for days cannot fill its storage trying. */
const MAX_QUEUE_LENGTH = 50;

function read() {
    try {
        const raw = localStorage.getItem(QUEUE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        /*
         * Corrupt JSON must not take the punch screen down with it.
         *
         * Storage can be written to by anything on the same origin, and a parse error here would
         * otherwise throw during module load — leaving an employee looking at a blank page rather
         * than a working clock-in button.
         */
        return [];
    }
}

function write(entries) {
    try {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(entries));
    } catch {
        // Quota exceeded or storage disabled. The punch is lost, but the caller is told.
        return false;
    }

    return true;
}

/** A v4-shaped uuid. `crypto.randomUUID` is unavailable on older Android WebViews. */
function uuid() {
    if (globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;

        return v.toString(16);
    });
}

export const punchQueue = {
    /** Everything waiting to be sent, oldest first. */
    all() {
        return read().sort((a, b) => new Date(a.claimed_at) - new Date(b.claimed_at));
    },

    count() {
        return read().length;
    },

    isEmpty() {
        return read().length === 0;
    },

    /**
     * Add an action.
     *
     * The id is generated HERE, once, and reused on every retry — that is what makes the server
     * able to recognise a retry as the same punch rather than a second one. Generating it at send
     * time would defeat the whole mechanism.
     *
     * @returns {boolean} false when there was nowhere to store it.
     */
    add(action) {
        const entries = read();

        if (entries.length >= MAX_QUEUE_LENGTH) {
            /*
             * Refused rather than silently dropping the oldest. Discarding somebody's clock-out to
             * make room for a later one loses a punch the employee believes was recorded, and the
             * cap is high enough that reaching it means something is genuinely wrong.
             */
            return false;
        }

        entries.push({
            client_uuid: uuid(),
            action,
            claimed_at: new Date().toISOString(),
        });

        return write(entries);
    },

    remove(clientUuid) {
        write(read().filter((entry) => entry.client_uuid !== clientUuid));
    },

    clear() {
        localStorage.removeItem(QUEUE_KEY);
    },

    /**
     * Drop entries too old for the server to accept.
     *
     * The backdating window is bounded server-side, so an entry older than it will be refused
     * every time it is retried. Left in place it would block the queue for ever: each drain would
     * stop at the same permanently-refused entry and everything behind it would never be sent.
     *
     * @returns {number} how many were dropped.
     */
    pruneExpired(now = new Date()) {
        const cutoff = new Date(now.getTime() - MAX_QUEUE_AGE_HOURS * 3600 * 1000);
        const entries = read();
        const kept = entries.filter((entry) => new Date(entry.claimed_at) > cutoff);

        if (kept.length !== entries.length) {
            write(kept);
        }

        return entries.length - kept.length;
    },

    /**
     * Send everything queued, oldest first.
     *
     * Stops at the first failure that is the connection's fault, so the order of the punches is
     * preserved — sending a clock-out before the clock-in it followed would be refused as
     * out-of-order by the server, and every entry would then fail in turn.
     *
     * @returns {Promise<{sent: number, remaining: number, failed: number, offline: boolean}>}
     */
    async drain() {
        this.pruneExpired();

        const entries = this.all();

        if (entries.length === 0) {
            return { sent: 0, remaining: 0, failed: 0, offline: false, needsSignIn: false };
        }

        let sent = 0;
        let failed = 0;

        for (const entry of entries) {
            try {
                await punchApi.act(entry.action, null, entry.client_uuid, entry.claimed_at);

                this.remove(entry.client_uuid);
                sent++;
            } catch (error) {
                // Still no connection: stop and keep everything, in order.
                if (! error.status) {
                    return {
                        sent,
                        remaining: this.count(),
                        failed,
                        offline: true,
                        needsSignIn: false,
                    };
                }

                /*
                 * An expired or missing session KEEPS the entry and stops the drain.
                 *
                 * This was a bug found in the browser, and an expensive one: the queue drained on
                 * page load, the session had expired, the server answered 401, and every queued
                 * punch was dropped as though the server had refused it. The employee's clock-out
                 * was deleted by the app trying to help.
                 *
                 * A session expiry is RECOVERABLE — the employee scans the code again and the
                 * punch lands. So the entry is kept and the queue stops, and the caller tells them
                 * to sign in. Treating "I do not know who you are" as "this punch is invalid"
                 * conflates two completely different answers.
                 */
                if (error.status === 401 || error.code === 'SESSION_EXPIRED') {
                    return {
                        sent,
                        remaining: this.count(),
                        failed,
                        offline: false,
                        needsSignIn: true,
                    };
                }

                /*
                 * The server answered and genuinely refused — a time outside the backdating
                 * window, or an action that arrived out of order. Retrying produces the same
                 * refusal, so the entry is dropped rather than blocking everything behind it, and
                 * it is COUNTED: a punch silently disappearing is the outcome this feature exists
                 * to prevent. The caller reports the number so the employee tells a manager.
                 */
                this.remove(entry.client_uuid);
                failed++;
            }
        }

        return { sent, remaining: this.count(), failed, offline: false, needsSignIn: false };
    },
};
