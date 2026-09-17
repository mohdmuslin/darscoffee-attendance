import { http, setAuthToken } from '../../shared/api';

/**
 * Console API surface.
 *
 * One module per concern so a screen imports only what it needs, following the
 * ordering system's structure so the conventions are familiar.
 */
export const authApi = {
    async login(email, password) {
        const { data } = await http.post('/auth/login', { email, password });

        return data.data;
    },

    async logout() {
        await http.post('/auth/logout');
    },

    async me() {
        const { data } = await http.get('/auth/me');

        return data.data.user;
    },
};

export const outletApi = {
    async list() {
        const { data } = await http.get('/admin/outlets');

        return data.data.outlets;
    },

    async create(payload) {
        const { data } = await http.post('/admin/outlets', payload);

        return data.data;
    },

    async update(id, payload) {
        const { data } = await http.patch(`/admin/outlets/${id}`, payload);

        return data.data;
    },

    async currentToken(id) {
        const { data } = await http.get(`/admin/outlets/${id}/token`);

        return data.data;
    },

    /**
     * Issue a replacement code, revoking the previous one.
     *
     * This is the reprint action, and reprinting is the ONLY way to revoke a printed
     * code — so it stays a single unceremonious call rather than a multi-step flow.
     */
    async regenerateToken(id) {
        const { data } = await http.post(`/admin/outlets/${id}/token`);

        return data.data;
    },

    async revokeToken(id) {
        const { data } = await http.delete(`/admin/outlets/${id}/token`);

        return data.data;
    },

    async printSheet(id) {
        const { data } = await http.get(`/admin/outlets/${id}/print-sheet`);

        return data.data;
    },
};

export const employeeApi = {
    async list(params = {}) {
        const { data } = await http.get('/admin/employees', { params });

        return data.data.employees;
    },

    async get(id) {
        const { data } = await http.get(`/admin/employees/${id}`);

        return data.data.employee;
    },

    async create(payload) {
        const { data } = await http.post('/admin/employees', payload);

        return data.data.employee;
    },

    async update(id, payload) {
        const { data } = await http.patch(`/admin/employees/${id}`, payload);

        return data.data.employee;
    },

    async deactivate(id) {
        const { data } = await http.post(`/admin/employees/${id}/deactivate`);

        return data.data.employee;
    },

    async activate(id) {
        const { data } = await http.post(`/admin/employees/${id}/activate`);

        return data.data.employee;
    },

    async setPin(id, pin) {
        const { data } = await http.put(`/admin/employees/${id}/pin`, { pin });

        return data.data.employee;
    },

    async clearPin(id) {
        const { data } = await http.delete(`/admin/employees/${id}/pin`);

        return data.data.employee;
    },

    async uploadPhoto(id, file) {
        const form = new FormData();
        form.append('photo', file);

        const { data } = await http.post(`/admin/employees/${id}/photo`, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return data.data.employee;
    },
};

export const userApi = {
    async list() {
        const { data } = await http.get('/admin/users');

        return data.data.users;
    },

    async create(payload) {
        const { data } = await http.post('/admin/users', payload);

        return data.data;
    },

    async update(id, payload) {
        const { data } = await http.patch(`/admin/users/${id}`, payload);

        return data.data;
    },

    async deactivate(id) {
        const { data } = await http.post(`/admin/users/${id}/deactivate`);

        return data.data;
    },

    async activate(id) {
        const { data } = await http.post(`/admin/users/${id}/activate`);

        return data.data;
    },
};

/**
 * Timesheets — read only, all of it.
 *
 * There is deliberately no method here that writes a time entry. Time is changed
 * through a correction and nothing else, because a direct write would leave no record
 * of who changed what, or why.
 */
export const timesheetApi = {
    async summary(params = {}) {
        const { data } = await http.get('/admin/timesheets/summary', { params });

        return data.data;
    },

    async forEmployee(id, params = {}) {
        const { data } = await http.get(`/admin/timesheets/employees/${id}`, { params });

        return data.data.timesheet;
    },

    async entries(params = {}) {
        const { data } = await http.get('/admin/timesheets/entries', { params });

        return data.data;
    },

    /**
     * Trigger the CSV download.
     *
     * Fetched as a blob rather than pointed at with a link: the endpoint is behind a
     * bearer token, and an <a href> cannot send an Authorization header — the same
     * problem the signed photo URLs exist to avoid.
     */
    async downloadCsv(params = {}) {
        const response = await http.get('/admin/timesheets/export', {
            params,
            responseType: 'blob',
        });

        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');

        link.href = url;
        link.download = filenameFrom(response.headers['content-disposition'])
            ?? `timesheet-${params.from ?? 'export'}.csv`;

        document.body.appendChild(link);
        link.click();
        link.remove();

        // Revoked immediately: an object URL is held by the document until released, so
        // leaving it leaks the whole file for the life of the tab.
        URL.revokeObjectURL(url);
    },
};

/** Pull the server's filename out of Content-Disposition, if it sent one. */
function filenameFrom(disposition) {
    if (! disposition) {
        return null;
    }

    const match = /filename="?([^";]+)"?/i.exec(disposition);

    return match ? match[1] : null;
}

export const correctionApi = {
    async list(params = {}) {
        const { data } = await http.get('/admin/corrections', { params });

        return data.data;
    },

    async pendingCount() {
        const { data } = await http.get('/admin/corrections/pending-count');

        return data.data.pending;
    },

    /** Change a recorded entry. Needs a reason; the server refuses without one. */
    async requestChange(entryId, changes, reason) {
        const { data } = await http.post(`/admin/corrections/entries/${entryId}`, { changes, reason });

        return data.data.correction;
    },

    /** Record a punch that never happened, for a shift with nothing to amend. */
    async requestMissing(payload) {
        const { data } = await http.post('/admin/corrections/missing', payload);

        return data.data.correction;
    },

    async history(entryId) {
        const { data } = await http.get(`/admin/corrections/entries/${entryId}/history`);

        return data.data;
    },

    async approve(id, note = null) {
        const { data } = await http.post(`/admin/corrections/${id}/approve`, { note });

        return data.data.correction;
    },

    async reject(id, note = null) {
        const { data } = await http.post(`/admin/corrections/${id}/reject`, { note });

        return data.data.correction;
    },
};

/**
 * The roster — the PLAN, kept apart from time entries, which are the ACTUAL.
 *
 * There is deliberately no delete method. Cancelling keeps the row, because a shift that
 * was rostered and then called off is what explains a no-show.
 */
export const shiftApi = {
    async list(params = {}) {
        const { data } = await http.get('/admin/shifts', { params });

        return data.data;
    },

    /** This week and next, for the roster landing view. */
    async current(params = {}) {
        const { data } = await http.get('/admin/shifts/current', { params });

        return data.data;
    },

    async get(id) {
        const { data } = await http.get(`/admin/shifts/${id}`);

        return data.data.shift;
    },

    /**
     * Create or amend.
     *
     * Times go as local wall-clock strings ('2026-09-21T09:00'), never with an offset. The
     * server attaches the outlet's timezone; sending an offset would mean trusting the
     * phone's clock and its timezone setting, and the failure mode is a shift eight hours
     * out with every lateness figure following it.
     */
    async create(payload) {
        const { data } = await http.post('/admin/shifts', payload);

        return data.data.shift;
    },

    async update(id, payload) {
        const { data } = await http.patch(`/admin/shifts/${id}`, payload);

        return data.data.shift;
    },

    async cancel(id, reason = null) {
        const { data } = await http.post(`/admin/shifts/${id}/cancel`, { reason });

        return data.data.shift;
    },

    async copy(payload) {
        const { data } = await http.post('/admin/shifts/copy', payload);

        return data.data;
    },
};

export const anomalyApi = {    async list(params = {}) {
        const { data } = await http.get('/admin/anomalies', { params });

        return data.data;
    },

    async review(id, note = null) {
        const { data } = await http.post(`/admin/anomalies/${id}/review`, { note });

        return data.data.anomaly;
    },

    /** The punch audit trail, including the failed attempts. */
    async events(params = {}) {
        const { data } = await http.get('/admin/punch-events', { params });

        return data.data;
    },
};

/**
 * Where the console keeps its token.
 *
 * Applied at module scope on load (see main.js) rather than inside a store action,
 * because a child component's onMounted runs before its parent's — so a request
 * fired during mount would otherwise go out unauthenticated. The ordering system hit
 * exactly this.
 */
export const TOKEN_KEY = 'dars.attendance_token';

export function applyStoredToken() {
    const token = localStorage.getItem(TOKEN_KEY);

    if (token) {
        setAuthToken(token);
    }

    return token;
}

export function storeToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
    setAuthToken(token);
}

export function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
    setAuthToken(null);
}
