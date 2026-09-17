/**
 * Formatting shared by the Phase 3 screens.
 *
 * Kept in one place because a timesheet is read by comparing numbers: if one screen
 * renders "8h 30m" and another "8.5", the manager has to do arithmetic to reconcile them
 * and will eventually get it wrong.
 */

/**
 * Seconds as "8h 30m".
 *
 * Mirrors WorkedHoursService::hm() deliberately. The server sends a preformatted label
 * with every figure and that is used wherever possible — this exists for the cases where
 * only raw seconds are to hand, and must not drift from the server's wording.
 */
export function hm(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const negative = seconds < 0;
    const total = Math.abs(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    const text = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;

    return negative ? `-${text}` : text;
}

/** A time of day, from an ISO string, in the browser's own timezone. */
export function timeOf(iso) {
    if (! iso) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/** A date and time, for an audit record where the day matters as much as the time. */
export function dateTime(iso) {
    if (! iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** A date, from a YYYY-MM-DD business date, without timezone shifting it. */
export function businessDate(date, opts = {}) {
    if (! date) {
        return '—';
    }

    /*
     * Parsed as local midnight rather than UTC. `new Date('2026-09-18')` is UTC midnight,
     * which renders as the PREVIOUS day for anyone west of Greenwich — so a timesheet
     * would show yesterday's date next to today's hours.
     */
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString([], {
        weekday: opts.weekday === false ? undefined : 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/** The weekday alone, for a dense timesheet row. */
export function weekday(date) {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString([], { weekday: 'short' });
}

/** Today and the preceding N-1 days, as YYYY-MM-DD. The default reporting window. */
export function lastDays(count) {
    const to = new Date();
    const from = new Date();

    from.setDate(from.getDate() - (count - 1));

    return { from: toIsoDate(from), to: toIsoDate(to) };
}

/** A local Date as YYYY-MM-DD, without the UTC shift `toISOString()` would introduce. */
export function toIsoDate(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Badge colours per timesheet day status. */
export const dayStatusTone = {
    ok: 'bg-green-100 text-green-800',
    open: 'bg-red-100 text-red-800',
    corrected: 'bg-blue-100 text-blue-800',
    adhoc: 'bg-stone-100 text-stone-600',
    no_show: 'bg-amber-100 text-amber-900',
};

/** Badge colours per anomaly severity. */
export const severityTone = {
    high: 'bg-red-100 text-red-800',
    warn: 'bg-amber-100 text-amber-900',
    info: 'bg-stone-100 text-stone-600',
};

/** Badge colours per correction status. */
export const correctionTone = {
    pending: 'bg-amber-100 text-amber-900',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-stone-200 text-stone-600',
};
