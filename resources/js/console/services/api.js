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
