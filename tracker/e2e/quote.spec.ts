/*
 * Cotizador en la tienda (M18, api-connector.md §8.3): el shortcode `[igr_quote]` pinta el
 * slot con su espacio reservado, `igr-w-quote.js` arma las preguntas y el contacto, y el
 * precio llega del hub simulado (connector/tests/fixtures/mock-hub.php).
 */
import { expect, test } from '@playwright/test';
import { hub, hubConfig, php, waitHub } from './store';

test.describe.configure({ mode: 'serial' });

const ID = 'qc_01JF0QUOTE0000000000000001';
const CONSENT_WA = 'Quiero recibir ofertas por WhatsApp. Puedo darme de baja cuando quiera.';
const calculator = {
    id: ID,
    name: 'Pintura de interiores',
    kind: 'area',
    currency: 'COP',
    definition: {
        fields: [
            { key: 'area', label: 'Área a pintar', type: 'number', required: true, unit: 'm²', min: 5, max: 2000, step: 1, default: null },
            { key: 'tipo', label: 'Tipo de pintura', type: 'select', required: true, options: [{ value: 'vinilo', label: 'Vinilo' }, { value: 'esmalte', label: 'Esmalte' }] },
        ],
        packages: [],
        note: 'Incluye materiales.',
    },
    capture: { required: true, fields: ['first_name', 'phone'], require: 'phone_or_email', consents: [{ channel: 'whatsapp', label: CONSENT_WA, version: 'v3' }], title: '¿A dónde te enviamos tu cotización?', cta: 'Ver mi cotización' },
};

let page_id = 0;

test.beforeAll(async () => {
    await hubConfig({ quote: calculator, widgets: [] });
    page_id = Number(php(`echo wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cotiza tu pintura', 'post_content' => '[igr_quote id="${ID}"]'));`));
});

test.afterAll(async () => {
    if (page_id) php(`wp_delete_post(${page_id}, true);`);
    await hubConfig({ quote: null, widgets: null });
});

test('cotiza en la página paso a paso: número revisado, opciones en tarjetas, contacto con casilla sin marcar y precio del hub', async ({ page, context }) => {
    await page.goto('/?page_id=' + page_id + '&utm_source=google&utm_medium=cpc');
    const slot = page.locator('[data-igr-slot="quote"]');
    await expect(slot).toHaveAttribute('style', /min-height:420px/);
    await expect(slot.getByRole('heading', { name: 'Pintura de interiores' })).toBeVisible();
    await expect(slot.getByText('Paso 1 de 3')).toBeVisible();

    // El número se revisa antes de seguir y el error queda junto al campo.
    await slot.getByRole('button', { name: 'Siguiente' }).click();
    await expect(slot.getByText('Escribe un número entre 5 y 2000.')).toBeVisible();
    await slot.getByLabel('Área a pintar').fill('40');
    await slot.getByRole('button', { name: 'Siguiente' }).click();

    // Una opción por tarjeta: al elegirla, avanza sola.
    await slot.getByText('Esmalte').click();
    await expect(slot.getByText('Paso 3 de 3')).toBeVisible();
    const consent = slot.getByRole('checkbox', { name: CONSENT_WA });
    await expect(consent).not.toBeChecked();

    // Sin el contacto, el hub responde 422 y el error queda junto al campo.
    await slot.getByRole('button', { name: 'Ver mi cotización' }).click();
    await expect(slot.getByRole('alert')).toHaveText('Revisa los campos marcados.');
    await expect(slot.getByText('Escribe tu WhatsApp o tu correo.')).toBeVisible();

    await slot.getByLabel('Nombre').fill('Ana');
    await slot.getByRole('textbox', { name: 'WhatsApp', exact: true }).fill('300 123 4567');
    await consent.check();
    await slot.getByRole('button', { name: 'Ver mi cotización' }).click();
    await expect(slot.getByRole('status')).toContainText('$596.000 – $729.000 COP');
    await expect(slot.getByRole('status')).toContainText('Incluye materiales.');

    const aid = (await context.cookies()).find((c) => c.name === '_igr_aid')?.value;
    const sent = await waitHub((s) => (s as unknown as { quotes: { answers: Record<string, unknown>; fields: string[]; consents: Record<string, boolean>; anonymous_id: string | null; utm: Record<string, string> }[] }).quotes.at(-1));
    expect(sent).toMatchObject({ answers: { area: 40, tipo: 'esmalte' }, fields: ['first_name', 'phone'], consents: { whatsapp: true }, utm: { utm_source: 'google', utm_medium: 'cpc' } });
    if (aid) expect(sent.anonymous_id).toBe(aid);

    // «Cotizar de nuevo» vuelve al formulario con lo que había.
    await slot.getByRole('button', { name: 'Cotizar de nuevo' }).click();
    await expect(slot.getByLabel('Área a pintar')).toHaveValue('40');
    const s = await hub();
    expect(s.schema_errors, 'ningún evento fuera del contrato').toEqual([]);
});

test('un cotizador que el hub no conoce no deja hueco', async ({ page }) => {
    await hubConfig({ quote: { ...calculator, id: 'qc_01JF0QUOTE0000000000000002' } });
    await page.goto('/?page_id=' + page_id);
    const slot = page.locator('[data-igr-slot="quote"]');
    await expect(slot).toBeHidden();
});
