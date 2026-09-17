import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PunchApp from './PunchApp.vue';

/**
 * The public clock-in PWA.
 *
 * No router: the flow is a short linear sequence (scan → PIN → photo → status),
 * and a URL structure would add states that need guarding without making the
 * screen any easier to use one-handed in a kitchen.
 */
const app = createApp(PunchApp);

app.use(createPinia());
app.mount('#punch-app');

/*
 * Registered after mount so the first paint is never delayed by the service worker.
 * It only caches the app shell — see public/sw.js — which means a weak signal shows
 * the app instead of the browser's error page.
 *
 * Deliberately not registering this in the console: the console is used on reliable
 * office wifi and caching its shell would risk serving a stale build against a
 * newer API.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // A failure here is not worth surfacing: the app works without it.
        });
    });
}
