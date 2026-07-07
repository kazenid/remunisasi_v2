import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, Link, Head } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// PrimeVue
import PrimeVue from 'primevue/config';
import Aura from '@primeuix/themes/aura';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import DialogService from 'primevue/dialogservice';

const appName = import.meta.env.VITE_APP_NAME || 'Sakai Laravel';

createInertiaApp({
    title: (title) => title ? `${title} — ${appName}` : appName,

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        // Inertia plugin
        app.use(plugin);

        // Named routes (Ziggy)
        app.use(ZiggyVue);

        // PrimeVue with Aura theme (matches sakai-vue)
        app.use(PrimeVue, {
            theme: {
                preset: Aura,
                options: {
                    darkModeSelector: '.app-dark',
                    cssLayer: {
                        name: 'primevue',
                        order: 'tailwind-base, primevue, tailwind-utilities',
                    },
                },
            },
        });

        // PrimeVue services
        app.use(ToastService);
        app.use(ConfirmationService);
        app.use(DialogService);

        // Global components
        app.component('InertiaLink', Link);
        app.component('InertiaHead', Head);

        app.mount(el);

        return app;
    },

    progress: {
        color: 'var(--p-primary-color)',
        showSpinner: true,
    },
});
