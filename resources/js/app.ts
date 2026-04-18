import '../css/app.css'; // Zorg dat je CSS ook geladen wordt
import './bootstrap.ts';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'; // Belangrijk!
import { createApp, createSSRApp, h, DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const page = resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue')
        );

        page.then((module) => {
            const layoutMeta = module.default.layout;

            module.default.layout = ((name: string) => {
                if (name === 'Welcome') {
                    return null;
                }

                if (name.startsWith('auth/')) {
                    if (layoutMeta === null) {
                        return null;
                    }

                    if (typeof layoutMeta === 'function') {
                        return layoutMeta;
                    }

                    const props =
                        layoutMeta && typeof layoutMeta === 'object'
                            ? layoutMeta
                            : {};
                    return (page: any) => h(AuthLayout, props, () => page);
                }

                if (layoutMeta) {
                    return layoutMeta;
                }

                if (name.startsWith('settings/')) {
                    return [AppLayout, SettingsLayout];
                }

                return AppLayout;
            })(name);
        });

        return page;
    },
    setup({ el, App, props, plugin }) {
        if (typeof window === 'undefined') {
            return createSSRApp({ render: () => h(App, props) }).use(plugin);
        }

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
