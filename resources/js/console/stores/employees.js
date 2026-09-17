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
    },
});
