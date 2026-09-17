import { defineStore } from 'pinia';
import { payApi, rateApi } from '../services/api';
import { lastDays } from '../lib/format';

/**
 * Pay rates, pay reports, and pay periods.
 *
 * One store rather than three because the screens cross-link: setting a rate from the pay list,
 * then seeing the figure move, then locking the period. Splitting them would mean every action
 * refreshed two stores in the right order.
 */
export const usePayStore = defineStore('pay', {
    state: () => ({
        /** The current calendar month, which is how this business thinks about pay. */
        from: null,
        to: null,

        summary: null,
        periodSummary: null,

        /** Rate list: one row per employee, with their current rate. */
        rates: [],
        requiresApproval: false,

        /** One employee's rate history and adjustments. */
        history: null,
        historyEmployeeId: null,

        periods: [],
        suggestion: null,

        /** A single period's reconciliation, when one is being examined. */
        reconciliation: null,

        outletId: '',
        includeInactive: false,

        loading: false,
        saving: false,
        error: null,
        fieldErrors: null,
        notice: null,
    }),

    getters: {
        /** Employees with no rate set — the ones who cannot be priced. */
        unpriced: (state) => state.rates.filter((row) => ! row.has_rate),

        /** Adjustments waiting on the owner. */
        pendingAdjustments: (state) => (state.history?.adjustments ?? []).filter((a) => a.awaiting_approval),

        totalAmount: (state) => state.summary?.totals?.total_amount ?? null,

        /** A one-line summary, so the important number is unmissable. */
        headline: (state) => {
            if (state.summary === null) {
                return null;
            }

            const unpriced = state.summary.totals?.unpriced_count ?? 0;

            if (unpriced > 0) {
                return `${unpriced} employee(s) have hours but no rate, so they are not in the total.`;
            }

            return `${state.summary.employees.length} employee(s) priced.`;
        },
    },

    actions: {
        /** Default to the current calendar month. */
        currentMonth() {
            const now = new Date();
            const first = new Date(now.getFullYear(), now.getMonth(), 1);

            const pad = (n) => String(n).padStart(2, '0');
            const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

            return { from: iso(first), to: iso(now) };
        },

        async loadSummary(from, to) {
            this.from = from;
            this.to = to;
            this.loading = true;
            this.error = null;

            try {
                const data = await payApi.summary({
                    from,
                    to,
                    outlet_id: this.outletId || undefined,
                    active_only: this.includeInactive ? 0 : 1,
                });

                this.summary = data;
                this.periodSummary = data.period ?? null;
            } catch (error) {
                this.error = error.message;
                this.summary = null;
            } finally {
                this.loading = false;
            }
        },

        async loadRates() {
            this.loading = true;
            this.error = null;

            try {
                const data = await rateApi.list({ active_only: this.includeInactive ? 0 : 1 });

                this.rates = data.employees;
                this.requiresApproval = data.requires_approval;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async loadHistory(employeeId) {
            this.loading = true;
            this.error = null;
            this.historyEmployeeId = employeeId;

            try {
                this.history = await rateApi.history(employeeId);
            } catch (error) {
                this.error = error.message;
                this.history = null;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Set a rate.
         *
         * Returns a result rather than a boolean so the form can keep its values and show field
         * errors — a half-typed rate must not be lost.
         */
        async saveRate(employeeId, payload) {
            this.saving = true;
            this.error = null;
            this.fieldErrors = null;

            try {
                await rateApi.set(employeeId, payload);

                await Promise.all([this.loadRates(), this.reloadSummary()]);

                return { ok: true };
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.fieldErrors;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        async saveAdjustment(payload) {
            this.saving = true;
            this.error = null;
            this.fieldErrors = null;

            try {
                const result = await rateApi.adjust(payload);

                if (this.historyEmployeeId) {
                    await this.loadHistory(this.historyEmployeeId);
                }

                await this.reloadSummary();

                this.notice = result.awaiting_approval
                    ? 'Adjustment recorded and waiting for the owner to approve.'
                    : 'Adjustment applied.';

                return { ok: true, awaitingApproval: result.awaiting_approval };
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.fieldErrors;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        async loadPeriods() {
            this.loading = true;

            try {
                const data = await payApi.periods();

                this.periods = data.periods;
                this.suggestion = data.suggestion;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async createPeriod(payload) {
            this.saving = true;
            this.error = null;

            try {
                await payApi.createPeriod(payload);
                await this.loadPeriods();

                return { ok: true };
            } catch (error) {
                this.error = error.message;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        /**
         * Lock a period, committing its figures.
         *
         * Reloads both the period list and the reconciliation, because after locking the state
         * of both has changed and showing one without the other would be misleading.
         */
        async lockPeriod(id) {
            this.saving = true;
            this.error = null;

            try {
                await payApi.lockPeriod(id);

                await this.loadPeriods();
                await this.loadReconciliation(id);

                this.notice = 'Period locked. The figures as they stand are now the record.';

                return { ok: true };
            } catch (error) {
                this.error = error.message;

                return { ok: false };
            } finally {
                this.saving = false;
            }
        },

        async loadReconciliation(id) {
            try {
                this.reconciliation = await payApi.reconcilePeriod(id);
            } catch (error) {
                this.error = error.message;
                this.reconciliation = null;
            }
        },

        async downloadCsv() {
            try {
                await payApi.downloadCsv({
                    from: this.from,
                    to: this.to,
                    outlet_id: this.outletId || undefined,
                    active_only: this.includeInactive ? 0 : 1,
                });

                return true;
            } catch (error) {
                this.error = error.message;

                return false;
            }
        },

        async reloadSummary() {
            if (this.from !== null && this.summary !== null) {
                await this.loadSummary(this.from, this.to);
            }
        },

        clearMessages() {
            this.error = null;
            this.fieldErrors = null;
            this.notice = null;
        },
    },
});
