import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.tsx'],
            refresh: true,
        }),
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
                // Dependencias estables en chunks propios: cambian poco y quedan en caché del navegador.
                codeSplitting: {
                    groups: [
                        { name: 'react', test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/ },
                        { name: 'data', test: /node_modules[\\/](@tanstack|react-router|zustand|i18next|react-i18next)[\\/]/ },
                        { name: 'ui', test: /node_modules[\\/](@radix-ui|cmdk|lucide-react|@floating-ui|tailwind-merge|clsx|class-variance-authority)[\\/]/ },
                        { name: 'forms', test: /node_modules[\\/](zod|react-hook-form|@hookform)[\\/]/ },
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
