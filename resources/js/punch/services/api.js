import { http, setPunchSession } from '../../shared/api';

/**
 * The public punch API.
 *
 * No login: kitchen crew have no account. Identity comes from an outlet code plus a
 * PIN, exchanged for a short-lived session held in memory and localStorage.
 *
 * The session is persisted because a phone may lock or reload mid-shift, and being
 * forced to re-scan and re-enter a PIN to end a break is exactly when people give up
 * and walk away — leaving segments open and hours wrong.
 */
const SESSION_KEY = 'dars.punch_session';

export const punchApi = {
    async start(token, pin) {
        const { data } = await http.post('/punch/start', { token, pin });

        return data.data;
    },

    async state() {
        const { data } = await http.get('/punch/state');

        return data.data.state;
    },

    async act(action, photo = null, clientUuid = null) {
        const { data } = await http.post('/punch/act', {
            action,
            photo,
            client_uuid: clientUuid,
        });

        return data;
    },

    async myHours() {
        const { data } = await http.get('/punch/hours');

        return data.data;
    },
};

export function restorePunchSession() {
    const stored = localStorage.getItem(SESSION_KEY);

    if (stored) {
        setPunchSession(stored);
    }

    return stored;
}

export function storePunchSession(token) {
    localStorage.setItem(SESSION_KEY, token);
    setPunchSession(token);
}

export function clearPunchSession() {
    localStorage.removeItem(SESSION_KEY);
    setPunchSession(null);
}
