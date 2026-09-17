import { defineStore } from 'pinia';
import { shiftApi } from '../services/api';

/**
 * The roster.
 *
 * Dates are handled as plain `YYYY-MM-DD` strings rather than Date objects throughout:
 * a `Date` carries a timezone, and every bug in this area comes from a local date being
 * quietly reinterpreted as UTC. The server sends `local_date` for each shift so the browser
 * never has to derive a day boundary itself.
 */
export const useShiftStore = defineStore('shifts', {
    state: () => ({
        /** The range being viewed, as YYYY-MM-DD. */
        from: null,
        to: null,
        timezone: null,

        /** [{ date, shifts: [], planned_seconds }] — grouped by LOCAL day by the server. */
        days: [],
        totals: [],

        outletId: '',
        includeCancelled: false,

        loading: false,
        saving: false,
        error: null,
        fieldErrors: null,

        /** Set briefly after a copy, because a bulk action needs confirmation. */
        notice: null,
    }),

    getters: {
        /** Every shift in the range, flattened, for counts and lookups. */
        all: (state) => state.days.flatMap((day) => day.shifts),

        shiftCount: (state) => state.days.reduce((total, day) => total + day.shifts.length, 0),

        /**
         * Days with nobody working.
         *
         * Read from `has_cover`, which the SERVER computes, rather than from
         * `shifts.length`. A day whose only shift was cancelled has a non-empty shift list
         * but no cover — and reporting that as covered is how a shop opens with nobody on.
         */
        daysWithoutCover: (state) => state.days.filter((day) => ! day.has_cover).map((day) => day.date),

        plannedSeconds: (state) => state.days.reduce((total, day) => total + day.planned_seconds, 0),
    },

    actions: {
        async loadRange(from, to, params = {}) {
            this.from = from;
            this.to = to;
            this.loading = true;
            this.error = null;

            try {
                const data = await shiftApi.list({
                    from,
                    to,
                    outlet_id: this.outletId || undefined,
                    // 1 / 0 rather than true / false: a query string carries text, and the
                    // numeric form is unambiguous in the network tab.
                    include_cancelled: this.includeCancelled ? 1 : 0,
                    ...params,
                });

                this.days = data.days;
                this.totals = data.totals;
                this.timezone = data.timezone;
                this.from = data.from;
                this.to = data.to;
            } catch (error) {
                this.error = error.message;
                this.days = [];
            } finally {
                this.loading = false;
            }
        },

        async reload() {
            if (this.from !== null) {
                await this.loadRange(this.from, this.to);
            }
        },

        /**
         * Create or amend a shift.
         *
         * Returns a result rather than a boolean so the form can distinguish "the server
         * refused this because the person is already rostered" — which needs the sheet kept
         * open with the message — from a generic failure.
         */
        async save(id, payload) {
            this.saving = true;
            this.error = null;
            this.fieldErrors = null;

            try {
                const shift = id === null
                    ? await shiftApi.create(payload)
                    : await shiftApi.update(id, payload);

                await this.reload();

                return { ok: true, shift };
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.fieldErrors;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        async cancel(id, reason = null) {
            this.saving = true;
            this.error = null;

            try {
                await shiftApi.cancel(id, reason);
                await this.reload();

                return { ok: true };
            } catch (error) {
                this.error = error.message;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        /**
         * Copy a range of shifts.
         *
         * Reloads and reports what happened: a bulk action that silently skipped half its
         * rows would leave the manager looking at a roster they believe is a complete copy.
         */
        async copy(payload) {
            this.saving = true;
            this.error = null;
            this.notice = null;

            try {
                const result = await shiftApi.copy(payload);

                await this.reload();

                this.notice = result.skipped > 0
                    ? `${result.created} shift(s) copied, ${result.skipped} skipped where a shift already existed.`
                    : `${result.created} shift(s) copied.`;

                setTimeout(() => {
                    this.notice = null;
                }, 6000);

                return { ok: true, result };
            } catch (error) {
                this.error = error.message;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        /** Copy this week to next week, which is the action a manager uses most. */
        async copyForward(weeks = 1) {
            if (this.from === null) {
                return { ok: false };
            }

            const targetFrom = addDays(this.from, weeks * 7);

            return this.copy({
                source_from: this.from,
                source_to: this.to,
                target_from: targetFrom,
                outlet_id: this.outletId || undefined,
                on_conflict: 'skip',
            });
        },

        clearMessages() {
            this.error = null;
            this.fieldErrors = null;
            this.notice = null;
        },
    },
});

/**
 * Add days to a `YYYY-MM-DD` string.
 *
 * Done with the Date constructor in LOCAL time then reformatted, never via
 * `toISOString()` — that converts to UTC first, so a date at midnight local west of
 * Greenwich comes back as the previous day.
 */
export function addDays(isoDate, days) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(year, month - 1, day + days);

    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The Monday of the week containing a `YYYY-MM-DD` date. */
export function startOfWeek(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(year, month - 1, day);

    // getDay() is 0 for Sunday, and a roster week starts on Monday.
    const offset = (date.getDay() + 6) % 7;

    return addDays(isoDate, -offset);
}
