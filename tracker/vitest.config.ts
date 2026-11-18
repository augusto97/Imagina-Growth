import { defineConfig } from 'vitest/config';

export default defineConfig({
    define: { __VERSION__: JSON.stringify('0.1.0') },
    test: {
        environment: 'happy-dom',
        globals: true,
        include: ['test/**/*.test.ts'],
        environmentOptions: { happyDOM: { url: 'https://tienda.co/' } },
    },
});
