import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import ConsoleApp from './ConsoleApp.vue';
import LoginView from './views/LoginView.vue';
import DashboardView from './views/DashboardView.vue';

/**
 * The management console.
 *
 * Routes are declared now so the shell is navigable; each view is filled in as its
 * phase lands. Outlet scoping is enforced by the SERVER on every request, so a
 * manager reaching a URL they should not see gets nothing back rather than
 * trusting this guard to hide it.
 */
const router = createRouter({
    history: createWebHistory('/console'),
    routes: [
        { path: '/', redirect: '/dashboard' },
        { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
        { path: '/dashboard', name: 'dashboard', component: DashboardView },
    ],
});

const app = createApp(ConsoleApp);

app.use(createPinia());
app.use(router);
app.mount('#console-app');
