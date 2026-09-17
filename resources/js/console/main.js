import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import ConsoleApp from './ConsoleApp.vue';
import { useAuthStore } from './stores/auth';

/*
 * Routes are gated client-side for a decent experience, but that is NOT the security
 * boundary. Every request is authorised by the server, so a manager reaching a URL
 * they should not see gets an empty list or a 404 rather than data. Hiding navigation
 * merely avoids showing people doors that do not open.
 */
const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('./views/LoginView.vue'),
        meta: { public: true },
    },
    {
        path: '/',
        redirect: '/dashboard',
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('./views/DashboardView.vue'),
    },
    {
        path: '/employees',
        name: 'employees',
        component: () => import('./views/EmployeesView.vue'),
    },
    {
        path: '/employees/new',
        name: 'employee-new',
        component: () => import('./views/EmployeeFormView.vue'),
    },
    {
        path: '/employees/:id',
        name: 'employee-edit',
        component: () => import('./views/EmployeeFormView.vue'),
        props: true,
    },
    {
        path: '/outlets',
        name: 'outlets',
        component: () => import('./views/OutletsView.vue'),
    },
    {
        path: '/outlets/:id/codes',
        name: 'outlet-codes',
        component: () => import('./views/OutletCodesView.vue'),
        props: true,
    },
    {
        path: '/accounts',
        name: 'accounts',
        component: () => import('./views/AccountsView.vue'),
        meta: { ownerOnly: true },
    },
];

const router = createRouter({
    history: createWebHistory('/console'),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    /*
     * Resolve the session once, before deciding anything. Without this a refresh on a
     * deep link would bounce to login and then back again, because the token has not
     * been checked yet.
     */
    if (! auth.ready) {
        await auth.restore();
    }

    if (to.meta.public) {
        // Already signed in? Skipping the login screen saves a pointless step.
        return auth.isAuthenticated ? { name: 'dashboard' } : true;
    }

    if (! auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    /*
     * Owner-only screens redirect rather than refuse: a manager landing on the
     * accounts page has usually followed a stale bookmark, and bouncing them to the
     * dashboard is friendlier than an error they cannot act on.
     */
    if (to.meta.ownerOnly && ! auth.isOwner) {
        return { name: 'dashboard' };
    }

    return true;
});

const app = createApp(ConsoleApp);

app.use(createPinia());
app.use(router);
app.mount('#console-app');
