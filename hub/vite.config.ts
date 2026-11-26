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
                        { name: 'ui', test: /node_modules[\\/](@radix-ui|cmdk|lucide-react|@floating-ui|tailwind-merge|clsx|class-variance-authority)[\\/]/ },
                        { name: 'forms', test: /node_modules[\\/](zod|react-hook-form|@hookform)[\\/]/ },
                        // Los textos (es-CO completo y lo que cambia en es-MX y es-VE): su propio archivo, que se baja en
                        // paralelo con el código y sigue en caché cuando una versión no cambia ningún texto.
                        { name: 'locale', test: /resources[\\/]js[\\/]locales[\\/]/ },
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
