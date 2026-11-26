import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

// Vitest no necesita el plugin de Laravel (manifiesto y recarga), y con CI=true este se niega a arrancar en modo «serve».
const underVitest = Boolean(process.env.VITEST);

export default defineConfig({
    plugins: [
        ...(underVitest
            ? []
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/main.tsx'],
                      refresh: true,
                  }),
              ]),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        rolldownOptions: {
            output: {
                /*
                 * Dependencias estables en chunks propios: cambian poco y quedan en la caché del navegador (los assets del
                 * build se sirven con caché de un año, infra/config/frankenphp/Caddyfile). Un grupo entra entero donde se
                 * use cualquiera de sus módulos: aquí va solo lo que el arranque carga igual. Lo pesado (gráficas, React
                 * Flow, GrapesJS y MJML) no lleva grupo: el reparto automático lo deja en chunks que piden solo sus
                 * pantallas. Pesos y pendientes en docs/rendimiento.md §3.
                 */
                codeSplitting: {
                    groups: [
                        { name: 'react', test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/ },
                        { name: 'data', test: /node_modules[\\/](@tanstack|react-router|zustand|i18next|react-i18next)[\\/]/ },
                        // cmdk queda fuera: la paleta ⌘K se carga al abrirse (components/layout/CommandPalette.tsx).
                        { name: 'ui', test: /node_modules[\\/](@radix-ui|lucide-react|@floating-ui|tailwind-merge|clsx|class-variance-authority)[\\/]/ },
                        { name: 'forms', test: /node_modules[\\/](zod|react-hook-form|@hookform)[\\/]/ },
                        // Los textos del arranque (es-<país>.json y los módulos que usa el marco de la app, lib/i18n.ts EAGER): su
                        // propio archivo, que se baja en paralelo con el código y sigue en caché cuando una versión no cambia
                        // ningún texto. Los demás módulos de textos van en chunks pequeños que pide cada pantalla
                        // (app/routeTexts.ts): si este grupo los incluyera, volverían todos al arranque.
                        {
                            name: 'locale',
                            test: /resources[\\/]js[\\/]locales[\\/](es-(CO|MX|VE)\.json|es-(CO|MX|VE)[\\/](ui|sections|billing)\.json)$/,
                        },
                    ],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        globals: false,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        css: false,
    },
});
