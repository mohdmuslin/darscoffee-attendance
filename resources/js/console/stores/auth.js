import { defineStore } from 'pinia';
import {
    applyStoredToken,
    authApi,
    clearToken,
    storeToken,
} from '../services/api';

/**
 * Token is applied at MODULE SCOPE, not inside an action.
 *
 * A child component's onMounted runs before its parent's, so anything that fetches
 * during mount would fire before a store could restore the token — and the first
 * requests of every page load would go out unauthenticated. The ordering system hit
 * exactly this, hence the module-level call.
 */
const restored = applyStoredToken();

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        // Null means "not yet known", distinct from false meaning "not signed in".
        ready: false,
        loading: false,
        error: null,
    }),

    getters: {
        isAuthenticated: (state) => state.user !== null,

        isOwner: (state) => state.user?.role === 'owner',

        /**
         * null means every outlet, an array means only those.
         *
         * Kept distinct rather than collapsing to an empty array, so the UI never
         * renders an unrestricted owner as having access to nothing.
         */
        visibleOutletIds: (state) => state.user?.visible_outlet_ids ?? null,

        canAdminister: (state) => state.user?.can_administer ?? false,
    },

    actions: {
        async restore() {
            if (restored === null) {
                this.ready = true;

                return;
            }

            try {
                this.user = await authApi.me();
            } catch {
                // A stale or revoked token: clear it so the login screen is shown
                // rather than a half-signed-in shell.
                clearToken();
                this.user = null;
            } finally {
                this.ready = true;
            }
        },

        async login(email, password) {
            this.loading = true;
            this.error = null;

            try {
                const payload = await authApi.login(email, password);

                storeToken(payload.token);
                this.user = payload.user;
                this.ready = true;

                return true;
            } catch (error) {
                this.error = error.message;

                return false;
            } finally {
                this.loading = false;
            }
        },

        async logout() {
            try {
                await authApi.logout();
            } catch {
                // Signing out locally is what matters; a failed call must not strand
                // someone in a session they asked to leave.
            }

            clearToken();
            this.user = null;
        },
    },
});

