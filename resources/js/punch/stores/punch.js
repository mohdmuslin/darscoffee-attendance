import { defineStore } from 'pinia';
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
    }),

    getters: {
        isSignedIn: (state) => state.session !== null && state.state !== null,

        currentState: (state) => state.state?.state ?? 'clocked_out',

        actions: (state) => state.state?.actions ?? [],

        /** Whether this outlet wants a photo, and therefore needs camera access. */
        needsPhoto: (state) => state.outlet?.requires_photo ?? true,
    },

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
                 * No HTTP status means the request never reached the server, so the
                 * punch definitely did not happen. Saying so explicitly stops the
                 * employee walking away assuming it did — an unrecorded clock-out is
                 * the expensive failure here. They retry when signal returns; the
                 * session is still valid.
                 */
                if (! error.status) {
                    this.error = 'No connection. Your punch was NOT recorded — try again once you have signal.';

                    return false;
                }

                return false;
            } finally {
                this.submitting = false;
            }
        },

        async loadHours() {
            this.loading = true;

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
