import { defineStore } from 'pinia';
import { outletApi } from '../services/api';

/**
 * Outlets and their clock-in codes.
 *
 * The list is already scoped by the SERVER, so `outlets` contains only what this
 * user may act on. Nothing here filters by permission, and it must not: a store that
 * also tried to enforce scope would suggest the server was not doing it.
 */
export const useOutletStore = defineStore('outlets', {
    state: () => ({
        outlets: [],
        loading: false,
        error: null,
    }),

    getters: {
        active: (state) => state.outlets.filter((outlet) => outlet.is_active),

        /** Outlets with no live code, so nobody can clock in there. */
        withoutCode: (state) => state.outlets.filter((outlet) => ! outlet.has_live_token),
    },

    actions: {
        async load() {
            this.loading = true;
            this.error = null;

            try {
                this.outlets = await outletApi.list();
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async create(payload) {
            const outlet = await outletApi.create(payload);

            await this.load();

            return outlet;
        },

        /**
         * Issue a replacement code, revoking the previous one.
         *
         * This is the reprint action. Reprinting is the ONLY way to revoke a printed
         * code, so it reloads immediately — a stale screen showing a code that no
         * longer works would send someone to pin up the wrong sheet.
         */
        async regenerateToken(id) {
            const result = await outletApi.regenerateToken(id);

            await this.load();

            return result;
        },

        async revokeToken(id) {
            const result = await outletApi.revokeToken(id);

            await this.load();

            return result;
        },
    },
});
