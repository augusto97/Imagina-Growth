// Temporal (fork G): construye la SPA en public/build-g5 sin los módulos que otros forks
// tienen a medio escribir. No se versiona: se borra al terminar.
import { mergeConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import base from './vite.config';

const stubbed = /features[\\/](leads|quotes)[\\/]routes\.tsx$/;

export default mergeConfig(
    { ...base, plugins: ((base.plugins ?? []) as any[]).flat(Infinity).filter((p: any) => !String(p?.name ?? '').startsWith('laravel')) },
    {
        plugins: [
            laravel({ input: ['resources/css/app.css', 'resources/js/main.tsx'], buildDirectory: 'build-g5', refresh: false }),
            { name: 'g5-stub', enforce: 'pre', load(id: string) { return stubbed.test(id) ? 'export const workspaceRoutes = [];' : null; } },
        ],
    },
);
