import { defineStore } from 'pinia';
import { correctionApi, anomalyApi, timesheetApi } from '../services/api';

/**
 * Apply a review action with shared bookkeeping.
 *
 * A plain function rather than a `#private` method: private class members are not valid
 * in a Pinia options object, and the three review actions differ only in which endpoint
 * they call — repeating the pending/error handling three times is how they drift apart.
 *
 * @param {object} store
 * @param {() => Promise<any>} action
 */
async function applyReview(store, action) {
    store.submitting = true;
    store.error = null;

    try {
        const result = await action();

        return { ok: true, result };
    } catch (error) {
        store.error = error.message;

        return { ok: false };
    } finally {
        store.submitting = false;
    }
}

/**
 * Timesheets, corrections and the anomaly queue.
 *
 * One store rather than three: these screens cross-link constantly — a manager spots a
 * problem on the timesheet, corrects it, and the anomaly queue changes as a result — and
 * splitting them would mean every action had to refresh two stores in the right order.
 *
 * The pending-correction count lives here because the navigation badge needs it, and a
 * badge that only updates when its own screen is open is worse than no badge.
 */
export const useTimesheetStore = defineStore('timesheets', {
    state: () => ({
        from: null,
        to: null,

        summary: null,
        timesheet: null,
        entries: null,
        corrections: null,
        anomalies: null,
        events: null,

        pendingCorrections: 0,
        unreviewedAnomalies: 0,

        loading: false,
        submitting: false,
        error: null,
        fieldErrors: null,
    }),

    actions: {
        /**
         * Adopt a reporting window and reload everything that depends on it.
         *
         * Kept in one action so the summary, the entry list and the export can never be
         * looking at three different ranges because one call was forgotten.
         */
        async setRange(from, to) {
            this.from = from;
            this.to = to;

            await Promise.all([this.loadSummary(), this.loadAnomalies(), this.loadCorrections()]);
        },

        async loadSummary(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                this.summary = await timesheetApi.summary({ from: this.from, to: this.to, ...params });
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async loadEmployee(employeeId) {
            this.loading = true;
            this.error = null;

            try {
                this.timesheet = await timesheetApi.forEmployee(employeeId, {
                    from: this.from,
                    to: this.to,
                });
            } catch (error) {
                this.error = error.message;
                this.timesheet = null;
            } finally {
                this.loading = false;
            }
        },

        async loadEntries(params = {}) {
            this.loading = true;

            try {
                this.entries = await timesheetApi.entries({ from: this.from, to: this.to, ...params });
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async loadCorrections(params = {}) {
            this.loading = true;

            try {
                const data = await correctionApi.list(params);

                this.corrections = data.corrections;
                this.pendingCorrections = data.corrections.filter((c) => c.is_pending).length;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async loadAnomalies(params = {}) {
            this.loading = true;

            try {
                const data = await anomalyApi.list(params);

                this.anomalies = data.anomalies;
                this.unreviewedAnomalies = data.counts?.unreviewed ?? 0;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        /** The punch audit trail, including the attempts that failed. */
        async loadEvents(params = {}) {
            this.loading = true;

            try {
                this.events = await anomalyApi.events(params);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Change a recorded entry.
         *
         * Returns a result object rather than a boolean so the view can distinguish "the
         * server refused this because the reason was missing" from "that failed" — the
         * first needs the form kept open with the message, the second does not.
         */
        async requestCorrection(entryId, changes, reason) {
            this.submitting = true;
            this.error = null;
            this.fieldErrors = null;

            try {
                const correction = await correctionApi.requestChange(entryId, changes, reason);

                return { ok: true, correction };
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.fieldErrors;

                return { ok: false };
            } finally {
                this.submitting = false;
            }
        },

        async requestMissingPunch(payload) {
            this.submitting = true;
            this.error = null;
            this.fieldErrors = null;

            try {
                const correction = await correctionApi.requestMissing(payload);

                return { ok: true, correction };
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.fieldErrors;

                return { ok: false };
            } finally {
                this.submitting = false;
            }
        },

        async approveCorrection(id, note = null) {
            return applyReview(this, () => correctionApi.approve(id, note));
        },

        async rejectCorrection(id, note = null) {
            return applyReview(this, () => correctionApi.reject(id, note));
        },

        async reviewAnomaly(id, note = null) {
            return applyReview(this, async () => {
                const anomaly = await anomalyApi.review(id, note);

                // Decremented rather than refetched: the badge should drop the moment the
                // manager acts, and refetching the whole queue for one row is wasteful.
                this.unreviewedAnomalies = Math.max(0, this.unreviewedAnomalies - 1);

                return anomaly;
            });
        },

        async downloadCsv(params = {}) {
            try {
                await timesheetApi.downloadCsv({ from: this.from, to: this.to, ...params });

                return true;
            } catch (error) {
                this.error = error.message;

                return false;
            }
        },

        clearError() {
            this.error = null;
            this.fieldErrors = null;
        },
    },
});
