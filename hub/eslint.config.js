import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/**'] },
    js.configs.recommended,
    ...tseslint.configs.strictTypeChecked,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            globals: globals.browser,
            parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
        },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            ...reactHooks.configs.recommended.rules,
            '@typescript-eslint/restrict-template-expressions': ['error', { allowNumber: true }],
            '@typescript-eslint/no-confusing-void-expression': 'off',
            '@typescript-eslint/no-misused-promises': ['error', { checksVoidReturn: { attributes: false } }],
        },
    },
    {
        files: ['vite.config.ts', 'eslint.config.js'],
        extends: [tseslint.configs.disableTypeChecked],
    },
);
