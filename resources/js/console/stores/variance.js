import { defineStore } from 'pinia';
import { varianceApi } from '../services/api';

/**
 * Planned versus actual.
 *
 * Read-only throughout. Nothing here writes anything, which is the point: the report exists to
 * show what the roster and the punches disagree about, and it must not be able to change
 * either side of that comparison.
 */
export const useVarianceStore = defineStore('variance', {
    state: () => ({
        from: null,
        to: null,

        /** Per-employee summary rows. */
        employees: [],
        totals: null,

        /** One employee's day-by-day detail. */
        detail: null,
        detailEmployeeId: null,

        outletId: '',
        includeInactive: false,

        loading: false,
        error: null,
    }),

    getters: {
        /** Employees whose planned and actual disagree — the ones worth a manager's time. */
        withVariance: (state) => state.employees.filter((row) => row.has_variance),

        hasAnything: (state) => state.employees.length > 0,

        /** A one-line summary for the header, so the important number is unmissable. */
        headline: (state) => {
            if (state.totals === null) {
                return null;
            }

            const problems = state.totals.no_show_count
                + state.totals.partial_count
                + state.totals.unplanned_count;

            return problems === 0
                ? 'Everything ran to plan.'
                : `${problems} thing(s) need looking at.`;
        },
    },

    actions: {
        async load(from, to) {
            this.from = from;
            this.to = to;
            this.loading = true;
            this.error = null;

            try {
                const data = await varianceApi.summary({
                    from,
                    to,
                    outlet_id: this.outletId || undefined,
                    // 1 / 0 rather than true / false: a query string carries text, and the
                    // numeric form is unambiguous in the network tab.
                    active_only: this.includeInactive ? 0 : 1,
                });

                this.employees = data.employees;
                this.totals = data.totals;
                this.from = data.from;
                this.to = data.to;
            } catch (error) {
                this.error = error.message;
                this.employees = [];
                this.totals = null;
            } finally {
                this.loading = false;
            }
        },

        async loadDetail(employeeId) {
            this.loading = true;
            this.error = null;
            this.detailEmployeeId = employeeId;

            try {
                this.detail = await varianceApi.forEmployee(employeeId, {
                    from: this.from,
                    to: this.to,
                });
            } catch (error) {
                this.error = error.message;
                this.detail = null;
            } finally {
                this.loading = false;
            }
        },

        async downloadCsv() {
            try {
                await varianceApi.downloadCsv({
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

        clear() {
            this.detail = null;
            this.detailEmployeeId = null;
            this.error = null;
        },
    },
});
