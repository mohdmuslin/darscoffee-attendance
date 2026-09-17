import axios from 'axios';

/**
 * Shared HTTP client for both frontends.
 *
 * A plain module-level axios instance, deliberately not a store: the console
 * applies its bearer token here at module scope so it exists before any child
 * component's onMounted fires. On the ordering system, applying it inside a store
 * caused the first requests of a page load to go out unauthenticated, because a
 * child mounts before its parent.
 */
export const http = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Bearer token for console sessions. */
export function setAuthToken(token) {
    if (token) {
        http.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete http.defaults.headers.common.Authorization;
    }
}

/**
 * Punch session token.
 *
 * Sent as a header rather than a cookie because the punch flow is public and
 * short-lived: the employee exchanges a PIN for a session that authorises only
 * their own clock actions for a few minutes. A cookie would persist far too long
 * on a shared or borrowed phone.
 */
export function setPunchSession(token) {
    if (token) {
        http.defaults.headers.common['X-Punch-Session'] = token;
    } else {
        delete http.defaults.headers.common['X-Punch-Session'];
    }
}

/**
 * Surface the server's message rather than a generic axios error.
 *
 * The API returns a consistent envelope, so the useful text is at
 * `response.data.message` and is what should reach the user.
 *
 * `fieldErrors` is also attached when the server returned a 422. Without it a form
 * could only show a summary message, and the per-field errors the validation layer
 * already produced would be invisible — which is exactly the information someone
 * filling in the form needs.
 */
http.interceptors.response.use(
    (response) => response,
    (error) => {
        const data = error.response?.data ?? {};

        const message = data.message
            ?? data.errors?.error?.[0]
            ?? error.message
            ?? 'Something went wrong.';

        const wrapped = new Error(message);
        wrapped.status = error.response?.status;
        wrapped.code = data.code;
        wrapped.fieldErrors = data.errors ?? null;

        return Promise.reject(wrapped);
    },
);
