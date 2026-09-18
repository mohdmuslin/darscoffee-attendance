import { defineStore } from 'pinia';
import { punchQueue } from '../lib/queue';
import {
    clearPunchSession,
    punchApi,
    restorePunchSession,
    storePunchSession,
} from '../services/api';

/**
 * Applied at module scope, matching the console: a component's onMounted runs before
 * its parent's, so a restore inside an action would race the first request.
 */
const restored = restorePunchSession();

/**
 * Timer that clears the confirmation banner.
 *
 * Held at module scope, NOT created inside `act()`. An earlier version did that, and
 * because a shift is a rapid sequence of actions the first timer was still pending when
 * the second confirmation appeared — so it wiped a message the employee had not read.
 */
let confirmationTimer = null;

export const usePunchStore = defineStore('punch', {
    state: () => ({
        /** 'scan' | 'pin' | 'ready' | 'done' */
        step: 'scan',
        session: restored,
        employee: null,
        outlet: null,
        state: null,
        hours: null,
        loading: false,
        submitting: false,
        error: null,
        /** Server error code, e.g. PHOTO_REQUIRED or SESSION_EXPIRED. */
        errorCode: null,

        /**
         * True when the server demanded a photo the client did not know was needed.
         *
         * A manager can switch photos on for an outlet while someone's session is already
         * live. The cached `requires_photo` is then stale, the button skips the camera, and
         * the punch is refused — leaving the employee pressing a button that cannot work,
         * with no way to reach a camera. Recorded as its own flag, rather than re-derived
         * from the outlet, because adopting the server's setting below would immediately
         * make such a condition invisible again.
         */
        photoWasDemanded: false,
        /** Set briefly after an action so the employee sees confirmation. */
        confirmation: null,

        /**
         * Punches waiting to reach the server.
         *
         * Held in state so the screen can show "1 punch waiting to send" — an employee who cannot
         * tell whether their clock-out was recorded will retry, and a retry is exactly what makes
         * a duplicate. Showing the queue is the cheapest way to stop them.
         */
        queued: punchQueue.count(),
        /** True while a drain is in progress, so two reconnects cannot overlap. */
        syncing: false,
        /** Result of the last drain, for a message the employee can act on. */
        lastSync: null,
    }),

    getters: {
        isSignedIn: (state) => state.session !== null && state.state !== null,

        currentState: (state) => state.state?.state ?? 'clocked_out',

        actions: (state) => state.state?.actions ?? [],

        /** Whether this outlet wants a photo, and therefore needs camera access. */
        needsPhoto: (state) => state.outlet?.requires_photo ?? true,
        /** Whether anything is waiting to be sent. */
        hasQueued: (state) => state.queued > 0,

        /**
         * Whether a queued punch may be missing a photo the outlet wants.
         *
         * A queued punch carries no photograph — base64 images would fill localStorage and break
         * the session token stored beside them. At an outlet that requires photos, the punch would
         * be REFUSED on sync, so the employee needs telling to see a manager rather than waiting
         * for a queue that can never deliver.
         *
         * Surfaced as a warning rather than prevented. Blocking the queue at such an outlet would
         * mean an employee with no signal cannot clock out at all, which is worse than a punch that
         * needs correcting afterwards.
         */
        queuedNeedsManager: (state) => state.queued > 0 && (state.outlet?.requires_photo ?? false),    },

    actions: {
        /**
         * Adopt the employee and outlet the server reports for this session.
         *
         * Needed on resume: the token is persisted, but the employee and outlet were
         * only in the response to `start`, so a refreshed page would otherwise show an
         * empty header — which reads as being logged out even though every button works.
         */
        applySubject(state) {
            const subject = state?.subject;

            if (! subject) {
                return;
            }

            this.employee = { name: subject.name, employee_code: subject.employee_code, photo_url: subject.photo_url };
            this.outlet = { name: subject.outlet, requires_photo: subject.requires_photo };
        },

        /**
         * Recover an existing session on load.
         *
         * A phone that locked mid-shift must not force a re-scan just to end a break.
         */
        async resume() {
            if (! this.session) {
                return false;
            }

            try {
                this.state = await punchApi.state();
                this.applySubject(this.state);
                this.step = 'ready';

                return true;
            } catch (error) {
                /*
                 * Only a rejected session is discarded. Keeping it when the phone is
                 * merely offline matters: the session is still valid, and throwing it
                 * away would force a kitchen worker with no signal to scan a code and
                 * re-enter a PIN for a session the server never cancelled. A network
                 * error is indistinguishable from an expired one on `status`, so the
                 * check is on the absence of an HTTP response.
                 */
                if (error.status === 401 || error.code === 'SESSION_EXPIRED') {
                    this.forget();

                    return false;
                }

                this.error = error.status
                    ? error.message
                    : 'You appear to be offline. Move somewhere with signal and try again.';

                return false;
            }
        },

        async start(token, pin) {
            this.submitting = true;
            this.error = null;

            try {
                const data = await punchApi.start(token, pin);

                storePunchSession(data.punch_token);

                this.session = data.punch_token;
                this.employee = data.employee;
                this.outlet = data.outlet;
                this.state = data.state;
                this.step = 'ready';

                return true;
            } catch (error) {
                this.error = error.message;

                return false;
            } finally {
                this.submitting = false;
            }
        },

        async act(action, photo = null) {
            this.submitting = true;
            this.error = null;
            this.errorCode = null;
            this.photoWasDemanded = false;

            try {
                const response = await punchApi.act(action, photo);

                this.state = response.data.state;
                this.applySubject(this.state);
                this.confirmation = response.message;

                /*
                 * Any earlier timer is cancelled first. Without this a rapid
                 * break → end break → clock out sequence loses its later confirmations
                 * to a pending timer from the first action.
                 */
                if (confirmationTimer) {
                    clearTimeout(confirmationTimer);
                }

                confirmationTimer = setTimeout(() => {
                    this.confirmation = null;
                    confirmationTimer = null;
                }, 4000);

                return true;
            } catch (error) {
                this.error = error.message;
                this.errorCode = error.code ?? null;

                /*
                 * The outlet now requires a photo and none was sent, because the client's
                 * cached setting was stale. Adopt the server's view AND remember why, so
                 * the screen can offer the camera instead of repeating a request that
                 * cannot succeed.
                 */
                if (error.code === 'PHOTO_REQUIRED') {
                    this.outlet = { ...(this.outlet ?? {}), requires_photo: true };
                    this.photoWasDemanded = true;
                }

                /*
                 * An expired session cannot be recovered by retrying; the employee must
                 * scan again. Clearing here means the screen moves them to the only
                 * action that actually works.
                 */
                if (error.code === 'SESSION_EXPIRED') {
                    this.forget();

                    return false;
                }

                /*
                 * No HTTP status means the request never reached the server. The punch did not
                 * happen — but the employee DID punch, so it is QUEUED rather than lost.
                 *
                 * This is the case the whole feature exists for. Previously the message was
                 * "your punch was NOT recorded", which was honest and useless: the employee's only
                 * options were to stand there retrying or to walk away with nothing recorded.
                 *
                 * The punch time is captured HERE, at the moment they pressed the button, not when
                 * the queue eventually drains. Queueing it now and sending the sync time later
                 * would invent hours they were not there.
                 */
                if (! error.status) {
                    /*
                     * A photo cannot be queued. Base64 images are hundreds of kilobytes, and
                     * localStorage caps around 5MB — filling it would break the session token
                     * stored alongside. So the photo is dropped, which at an outlet that requires
                     * one means the punch will be refused on sync.
                     *
                     * That is stated plainly rather than hidden, and the flow still queues: an
                     * employee with no signal who cannot clock out at all is worse off than one
                     * whose punch needs a manager's correction afterwards.
                     */
                    const queuedOk = punchQueue.add(action);

                    this.queued = punchQueue.count();

                    if (! queuedOk) {
                        this.error = 'No connection, and this phone has no room to store the punch. Tell a manager.';

                        return false;
                    }

                    this.confirmation = photo === null
                        ? 'No connection. Saved on this phone — it will send when you have signal.'
                        : 'No connection. Saved without the photo — a manager will need to add it.';

                    if (confirmationTimer) {
                        clearTimeout(confirmationTimer);
                    }

                    confirmationTimer = setTimeout(() => {
                        this.confirmation = null;
                        confirmationTimer = null;
                    }, 6000);

                    /*
                     * The state is advanced locally, so the buttons reflect what the employee just
                     * did. Leaving the screen on "clock in" after they pressed clock in would
                     * invite a second press — and the queue would then hold two clock-ins.
                     *
                     * This is a LOCAL prediction, not the server's answer. It is corrected on the
                     * next drain, when the server returns the real state.
                     */
                    this.state = this.predictState(action, this.state);
                    this.error = null;

                    return true;
                }

                return false;
            } finally {
                this.submitting = false;
            }
        },

        /**
         * Predict the state after an action, for use while offline only.
         *
         * Necessary because the buttons are driven by the server's state, and a queued punch
         * produces no response. Without a prediction the screen still offers "clock in" after the
         * employee pressed it, so they press it again — and the queue then holds two clock-ins.
         *
         * This is deliberately a SMALL model of the same rules the server applies, and it is
         * REPLACED by the server's answer on the next drain. It is not a second source of truth;
         * it is a placeholder that stops the screen lying for a few minutes.
         *
         * Actions are filtered to the single one that follows, so the employee cannot build up a
         * sequence of contradictory punches in the queue.
         */
        predictState(action, current) {
            const base = current ?? {};

            const next = {
                clock_in: 'working',
                start_break: 'on_break',
                end_break: 'working',
                clock_out: 'clocked_out',
            }[action] ?? base.state ?? 'clocked_out';

            const labels = {
                clocked_out: 'Not clocked in',
                working: 'Clocked in',
                on_break: 'On a break',
            };

            /*
             * Both the WORK actions offered, because from `working` the employee may either break
             * or finish. The server narrows this further on the next refresh.
             */
            const actions = {
                clocked_out: ['clock_in'],
                working: ['start_break', 'clock_out'],
                on_break: ['end_break', 'clock_out'],
            }[next] ?? [];

            return {
                ...base,
                state: next,
                label: labels[next] ?? base.label,
                actions,
                /*
                 * Marked so the screen can say the state is not yet confirmed. Without this the
                 * employee would read "Clocked in" as the server having accepted the punch.
                 */
                pending: true,
            };
        },

        /**
         * Send everything queued.
         *
         * Called on reconnect and after a successful action, because the first sign the phone is
         * back online is usually the employee doing something else. The queued punches then drain
         * in the background rather than at a moment they have to think about.
         */
        async drainQueue() {
            // A second drain while one is running would send every entry twice. The server would
            // deduplicate, but only by rejecting the second attempt as a duplicate — noise in the
            // trail for no reason.
            if (this.syncing || punchQueue.isEmpty()) {
                this.queued = punchQueue.count();

                return this.lastSync;
            }

            this.syncing = true;

            try {
                const result = await punchQueue.drain();

                this.queued = punchQueue.count();
                this.lastSync = result;

                if (result.needsSignIn) {
                    /*
                     * The queue is intact and waiting, so this is an instruction, not a failure.
                     * Saying "a punch could not be recorded" here would be a lie — nothing was
                     * lost — and would send the employee to a manager for a problem that scanning
                     * the code solves.
                     */
                    this.error = 'Your saved punches are safe. Scan the code and enter your PIN to send them.';
                } else if (result.failed > 0) {
                    /*
                     * A genuine refusal is reported, not swallowed. It means a punch the employee
                     * believes was recorded has been dropped, and they need to tell a manager — the
                     * correction path exists for exactly this.
                     */
                    this.error = result.failed === 1
                        ? 'One saved punch could not be recorded. Please tell a manager.'
                        : `${result.failed} saved punches could not be recorded. Please tell a manager.`;
                }

                /*
                 * The server's state replaces the local prediction once anything has landed, because
                 * only it knows the truth after a drain that may have spanned several actions.
                 */
                if (result.sent > 0 && this.session) {
                    await this.resume();
                }

                return result;
            } finally {
                this.syncing = false;
            }
        },

        async loadHours() {            this.loading = true;

            try {
                this.hours = await punchApi.myHours();
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        forget() {
            clearPunchSession();

            /*
             * The queue is cleared too, and it must be.
             *
             * The queue belongs to the person who created it, and this is a SHARED phone in a
             * kitchen. A queue surviving a logout would drain under the next person's session —
             * attributing one employee's punches to another, which is exactly the problem the
             * punch photo and the anomaly queue exist to detect.
             *
             * The cost is real: an employee who logs out with punches still queued loses them. That
             * is the lesser harm, and the sync warning exists to tell them before they do.
             */
            punchQueue.clear();
            this.queued = 0;
            this.lastSync = null;

            if (confirmationTimer) {
                clearTimeout(confirmationTimer);
                confirmationTimer = null;
            }

            this.session = null;
            this.employee = null;
            this.outlet = null;
            this.state = null;
            this.hours = null;
            this.step = 'scan';
        },
    },
});
