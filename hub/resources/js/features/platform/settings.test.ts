import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import type { SettingField } from './settings-api';
import {
    SECTION_GROUPS,
    SETTING_SECTIONS,
    TOOL_SECTIONS,
    buildPayload,
    displayValue,
    effectiveBool,
    fieldErrors,
    parseWaRate,
    pruneDraft,
    randomToken,
    sectionFromParam,
} from './settings-model';

function field(key: string, patch: Partial<SettingField> = {}): SettingField {
    return { key, type: 'text', options: null, min: null, max: null, generate: null, value: null, fallback: null, source: null, configured: false, hint: null, ...patch };
}

describe('Plataforma → Ajustes', () => {
    it('abre la sección de la dirección o el correo', () => {
        expect(sectionFromParam('meta')).toBe('meta');
        expect(sectionFromParam('wa_rates')).toBe('wa_rates');
        expect(sectionFromParam('base_de_datos')).toBe('mail');
        expect(sectionFromParam(null)).toBe('mail');
        // Cada sección está en un grupo del menú, una sola vez.
        const grouped = SECTION_GROUPS.flatMap((g) => g.sections);
        expect([...grouped].sort()).toEqual([...SETTING_SECTIONS, ...TOOL_SECTIONS].sort());
    });

    it('envía solo lo que cambió, con números como números y vacío para volver al servidor', () => {
        const fields = [
            field('smtp_host', { value: 'smtp.viejo.test', source: 'panel' }),
            field('smtp_port', { type: 'int', fallback: 2525, source: 'server' }),
            field('smtp_password', { type: 'secret', configured: true, source: 'panel', hint: '…1234' }),
            field('from_name'),
            field('driver', { type: 'enum', options: ['smtp', 'ses', 'log'], value: 'smtp', source: 'panel' }),
        ];
        const draft = { smtp_host: '  smtp.nuevo.test ', smtp_port: '587', smtp_password: ' clave con espacios ', driver: null };
        expect(buildPayload(fields, draft)).toEqual({ smtp_host: 'smtp.nuevo.test', smtp_port: 587, smtp_password: ' clave con espacios ', driver: null });
        expect(buildPayload(fields, { smtp_port: '' })).toEqual({ smtp_port: null });
        expect(buildPayload(fields, {})).toEqual({});
    });

    it('no cuenta como cambio lo que ya está guardado ni un secreto vacío', () => {
        const fields = [
            field('smtp_host', { value: 'smtp.test', source: 'panel' }),
            field('smtp_password', { type: 'secret', configured: true, source: 'server' }),
            field('api_key', { type: 'secret', configured: true, source: 'panel' }),
            field('enabled', { type: 'bool', value: true, source: 'panel' }),
            field('open', { type: 'bool', fallback: false, source: 'server' }),
        ];
        expect(pruneDraft(fields, { smtp_host: 'smtp.test ', smtp_password: '', enabled: true, open: false })).toEqual({ open: false });
        // Quitar un secreto solo tiene sentido si lo guardó el panel (el del servidor no se borra desde aquí).
        expect(pruneDraft(fields, { smtp_password: null, api_key: null })).toEqual({ api_key: null });
        expect(pruneDraft(fields, { enabled: null, smtp_host: '' })).toEqual({ enabled: null, smtp_host: '' });
    });

    it('lee el estado de una casilla: lo pendiente, lo del panel o lo del servidor', () => {
        const panel = field('require_2fa', { type: 'bool', value: false, fallback: true, source: 'panel' });
        const server = field('ai', { type: 'bool', fallback: true, source: 'server' });
        expect(effectiveBool(panel, {})).toBe(false);
        expect(effectiveBool(panel, { require_2fa: null })).toBe(true);
        expect(effectiveBool(server, {})).toBe(true);
        expect(effectiveBool(server, { ai: false })).toBe(false);
    });

    it('arma tokens al azar para copiarlos antes de guardar', () => {
        const fixed = { getRandomValues: <T extends ArrayBufferView | null>(a: T) => (a instanceof Uint8Array ? (a.fill(171), a) : a) };
        expect(randomToken(4, fixed as unknown as Crypto)).toBe('abababab');
        const a = randomToken();
        expect(a).toMatch(/^[0-9a-f]{48}$/);
        expect(randomToken()).not.toBe(a);
    });

    it('muestra los valores del servidor sin jerga y toma los errores por campo', () => {
        expect(displayValue(true, 'Sí', 'No', 'es-CO')).toBe('Sí');
        expect(displayValue(10000, 'Sí', 'No', 'es-CO')).toBe('10.000');
        expect(displayValue(null, 'Sí', 'No', 'es-CO')).toBe('');
        expect(fieldErrors({ 'values.smtp_port': 'El valor no puede ser mayor que 65535.', password: 'x' })).toEqual({ smtp_port: 'El valor no puede ser mayor que 65535.' });
        expect(parseWaRate('0,0125')).toBe(0.0125);
        expect(parseWaRate('2')).toBeNull();
        expect(parseWaRate('')).toBeNull();
    });

    it('cada campo y cada sección tiene su nombre y su ayuda en es-CO', () => {
        const t = i18n.getFixedT('es-CO');
        const has = (k: string) => i18n.exists(k, { lng: 'es-CO' }) && t(k) !== k;
        const missing: string[] = [];
        for (const s of [...SETTING_SECTIONS, ...TOOL_SECTIONS]) {
            for (const part of ['title', 'description']) if (!has(`platformSettings.sections.${s}.${part}`)) missing.push(`sections.${s}.${part}`);
        }
        for (const g of SECTION_GROUPS) if (!has(`platformSettings.groups.${g.key}`)) missing.push(`groups.${g.key}`);
        expect(missing).toEqual([]);
    });
});
