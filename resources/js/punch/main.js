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
