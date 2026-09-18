import { defineStore } from 'pinia';
import { employeeApi } from '../services/api';

export const useEmployeeStore = defineStore('employees', {
    state: () => ({
        employees: [],
        filters: {
            search: '',
            outlet_id: '',
            include_inactive: false,
        },
        loading: false,
        saving: false,
        error: null,
    }),

    getters: {
        active: (state) => state.employees.filter((employee) => employee.is_active),

        /** Employees who have no PIN yet — they cannot clock in until one is set. */
        withoutPin: (state) => state.employees.filter((employee) => !employee.has_pin),

        /** Employees whose PIN is locked out after repeated failures. */
        pinLocked: (state) => state.employees.filter((employee) => employee.pin_locked),

        /**
         * Employees holding a photograph with no consent behind it.
         *
         * The PDPA backlog. A count rather than a filter, because it is a standing number that
         * should reach zero — not something to go looking for.
         */
        withoutConsent: (state) => state.employees.filter((employee) => employee.needs_consent),
    },

    actions: {
        async load() {
            this.loading = true;
            this.error = null;

            try {
                const params = {};

                if (this.filters.search) {
                    params.search = this.filters.search;
                }

                if (this.filters.outlet_id) {
                    params.outlet_id = this.filters.outlet_id;
                }

                if (this.filters.include_inactive) {
                    params.include_inactive = 1;
                }

                this.employees = await employeeApi.list(params);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async save(id, payload) {
            this.saving = true;
            this.error = null;

            try {
                const employee = id === null
                    ? await employeeApi.create(payload)
                    : await employeeApi.update(id, payload);

                await this.load();

                return employee;
            } catch (error) {
                /*
                 * Rethrown so the form can keep its values and show the field errors.
                 * A silent failure here would lose a half-typed employee record.
                 */
                this.error = error.message;

                throw error;
            } finally {
                this.saving = false;
            }
        },

        async setPin(id, pin) {
            const employee = await employeeApi.setPin(id, pin);

            await this.load();

            return employee;
        },

        async clearPin(id) {
            const employee = await employeeApi.clearPin(id);

            await this.load();

            return employee;
        },

        async toggleActive(employee) {
            if (employee.is_active) {
                await employeeApi.deactivate(employee.id);
            } else {
                await employeeApi.activate(employee.id);
            }

            await this.load();
        },

        async uploadPhoto(id, file) {
            const employee = await employeeApi.uploadPhoto(id, file);

            await this.load();

            return employee;
        },

        /** Record PDPA consent. The recorder is taken from the token server-side. */
        async recordConsent(id, payload) {
            this.saving = true;

            try {
                const employee = await employeeApi.recordConsent(id, payload);

                await this.load();

                return employee;
            } finally {
                this.saving = false;
            }
        },

        /**
         * Withdraw consent.
         *
         * Reloads afterwards because withdrawal does more than set a field: the profile photo is
         * deleted, so the row that comes back has `photo_url: null`. Leaving the stale avatar on
         * screen would suggest the withdrawal had not taken effect.
         */
        async withdrawConsent(id, note = null) {
            this.saving = true;

            try {
                const employee = await employeeApi.withdrawConsent(id, note);

                await this.load();

                return employee;
            } finally {
                this.saving = false;
            }
        },
    },
});
