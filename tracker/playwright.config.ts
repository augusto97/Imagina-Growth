/*
 * Prueba de punta a punta del lado del navegador: tienda WooCommerce real
 * (connector) + hub simulado (connector/tests/fixtures/mock-hub.php).
 *
 *   IGR_E2E_WP_DIR=/ruta/wordpress IGR_E2E_WP_CLI="php /ruta/wp-cli.phar --allow-root" npm run e2e -- --trace off
 *
 * Levanta la tienda en 127.0.0.1:8080 y el hub simulado en 127.0.0.1:8090, conecta
 * el sitio, corre e2e/*.spec.ts y al final deja el sitio desconectado.
 */
import { defineConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const wp = process.env['IGR_E2E_WP_DIR'] ?? '';
if (!wp) throw new Error('Falta IGR_E2E_WP_DIR (ruta de WordPress con WooCommerce y el connector).');
const data = process.env['MOCK_HUB_DATA'] ?? join(tmpdir(), 'igr-e2e-hub');
mkdirSync(data, { recursive: true });
process.env['MOCK_HUB_DATA'] = data;
process.env['IGR_HUB_URL'] = 'http://127.0.0.1:8090';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    reporter: [['list']],
    globalSetup: './e2e/global-setup.ts',
    use: { baseURL: 'http://127.0.0.1:8080', trace: 'off', locale: 'es-CO' },
    webServer: [
        {
            command: `php -S 127.0.0.1:8090 ${join(import.meta.dirname, '../connector/tests/fixtures/mock-hub.php')}`,
            url: 'http://127.0.0.1:8090/_state',
            reuseExistingServer: false,
            stdout: 'ignore',
            stderr: 'ignore',
            env: { MOCK_HUB_DATA: data },
        },
        {
            command: `php -S 127.0.0.1:8080 -t ${wp}`,
            url: 'http://127.0.0.1:8080/wp-login.php',
            reuseExistingServer: false,
            timeout: 60_000,
            stdout: 'ignore',
            stderr: 'ignore',
            env: { IGR_HUB_URL: 'http://127.0.0.1:8090', PHP_CLI_SERVER_WORKERS: '4' },
        },
    ],
});
