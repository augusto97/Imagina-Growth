import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { SettingField, SettingsSection } from '@/features/platform/settings-api';
import i18n from '@/lib/i18n';
import { ConnectionGuide } from './ConnectionGuide';
import {
    CONNECTION_GUIDES,
    GUIDE_LINKS,
    GUIDE_SECTIONS,
    PLATFORM_GUIDES,
    type GuideStep,
    type GuideValueKey,
    guideProgress,
    guideStartsOpen,
    problemList,
    stepStatus,
    stepValues,
    textList,
} from './model';
import { SettingsGuide } from './SettingsGuide';

afterEach(cleanup);

const t = (key: string) => i18n.t(key);
const list = (key: string) => i18n.t(key, { returnObjects: true }) as unknown;
const guidesJson = JSON.parse(readFileSync(join(__dirname, '..', '..', 'locales', 'es-CO', 'guides.json'), 'utf8')) as {
    guides: { platform: Record<string, unknown>; connections: Record<string, unknown>; values: Record<string, string> };
};

function field(key: string, configured: boolean): SettingField {
    return { key, type: 'text', options: null, min: null, max: null, generate: null, value: null, fallback: null, source: configured ? 'panel' : null, configured, hint: null };
}

function section(key: string, configured: string[], info: SettingsSection['info'] = {}, all: string[] = configured): SettingsSection {
    return { key, fields: all.map((k) => field(k, configured.includes(k))), info };
}

describe('guías de Plataforma → Ajustes', () => {
    it('cubre justo las secciones con credenciales de un tercero (las mismas que exige la prueba del hub)', () => {
        expect([...GUIDE_SECTIONS].sort()).toEqual(Object.keys(guidesJson.guides.platform).sort());
        expect([...GUIDE_SECTIONS].sort()).toEqual(['ai', 'google', 'mail', 'meta', 'monitor', 'ses', 'tiktok']);
    });

    it.each(GUIDE_SECTIONS)('la guía de %s tiene todos sus textos', (key) => {
        const base = `guides.platform.${key}`;
        expect(i18n.exists(`${base}.intro`)).toBe(true);
        expect(i18n.exists(`${base}.check`)).toBe(true);
        expect(textList(list(`${base}.before`)).length).toBeGreaterThan(0);
        expect(problemList(list(`${base}.problems`)).length).toBeGreaterThan(1);
        const guide = PLATFORM_GUIDES[key];
        expect(guide.section).toBe(key);
        expect(guide.minutes).toBeGreaterThan(0);
        for (const step of guide.steps) {
            expect(i18n.exists(`${base}.steps.${step.id}.title`), `${key}.${step.id}`).toBe(true);
            expect(textList(list(`${base}.steps.${step.id}.body`)).length, `${key}.${step.id}`).toBeGreaterThan(0);
            for (const v of step.values ?? []) expect(i18n.exists(`guides.values.${v}`), v).toBe(true);
            for (const l of step.links ?? []) expect(i18n.exists(`guides.links.${l}`), l).toBe(true);
        }
        // Ningún paso sobra en los textos (uno renombrado en el código dejaría su texto huérfano).
        const steps = Object.keys((guidesJson.guides.platform[key] as { steps: Record<string, unknown> }).steps);
        expect(steps.sort()).toEqual(guide.steps.map((s) => s.id).sort());
    });

    it('todos los enlaces van por https a la consola o la documentación oficial', () => {
        for (const [key, href] of Object.entries(GUIDE_LINKS)) {
            const url = new URL(href);
            expect(url.protocol, key).toBe('https:');
            expect(i18n.exists(`guides.links.${key}`), key).toBe(true);
        }
    });

    it('los pasos que llenan campos los nombran como el panel y esos campos existen en el registro', () => {
        const labels = JSON.parse(readFileSync(join(__dirname, '..', '..', 'locales', 'es-CO', 'platform-settings.json'), 'utf8')) as {
            platformSettings: { fields: Record<string, Record<string, { label: string }>> };
        };
        for (const key of GUIDE_SECTIONS) {
            for (const step of PLATFORM_GUIDES[key].steps) {
                for (const f of step.fields ?? []) {
                    expect(labels.platformSettings.fields[key]?.[f]?.label, `${key}.${f}`).toBeTruthy();
                }
            }
        }
    });

    it('muestra los valores tal como los calculó el hub y omite los que no vinieron', () => {
        const step: GuideStep = { id: 'x', values: ['callback_url', 'scopes', 'iam_policy', 'legacy_callback_url'] };
        const policy = '{\n    "Version": "2012-10-17"\n}';
        const s = section('google', [], { guide: { callback_url: 'https://growth.imagina.cloud/oauth/google/callback', scopes: ['openid', 'email'], iam_policy: policy } });
        expect(stepValues(step, s)).toEqual([
            { key: 'callback_url', text: 'https://growth.imagina.cloud/oauth/google/callback', list: null, multiline: false },
            { key: 'scopes', text: 'openid\nemail', list: ['openid', 'email'], multiline: false },
            { key: 'iam_policy', text: policy, list: null, multiline: true },
        ]);
        expect(stepValues(step, section('google', []))).toEqual([]);
    });

    it('marca listo el paso cuyos campos están guardados y, si tiene, su señal propia', () => {
        const fields = ['app_id', 'app_secret', 'es_config_id', 'login_config_id', 'webhook_verify_token'];
        const half = section('meta', ['app_id', 'app_secret'], { connected_accounts: 0 }, fields);
        const guide = PLATFORM_GUIDES.meta;
        const app = guide.steps.find((s) => s.id === 'app');
        const live = guide.steps.find((s) => s.id === 'live');
        const legal = guide.steps.find((s) => s.id === 'legal');
        expect(app && stepStatus(app, half)).toBe('done');
        expect(live && stepStatus(live, half)).toBe('todo');
        expect(legal && stepStatus(legal, half)).toBe('unknown');
        const progress = guideProgress(guide, half);
        expect(progress).toMatchObject({ done: 1, total: 5, complete: false });

        const full = section('meta', fields, { connected_accounts: 2, configured: true }, fields);
        expect(guideProgress(guide, full)).toMatchObject({ done: 5, total: 5, complete: true });
        expect(guideStartsOpen(full, guideProgress(guide, full))).toBe(false);
        expect(guideStartsOpen(half, progress)).toBe(true);
    });

    it('SES queda listo cuando «Preparar ahora» salió bien', () => {
        const prepare = PLATFORM_GUIDES.ses.steps.find((s) => s.id === 'prepare');
        const ok = section('ses', [], { last_setup: { status: 'ok', message: '', configuration_set: 'igr-events', topic: null, permission: null, changed: false, checked_at: '' } });
        expect(prepare && stepStatus(prepare, ok)).toBe('done');
        expect(prepare && stepStatus(prepare, section('ses', []))).toBe('todo');
    });

    it('se ve abierta con los valores para copiar si la sección no está lista', () => {
        const s = section('tiktok', [], { configured: false, guide: { callback_url: 'https://growth.imagina.cloud/oauth/tiktok/callback' } }, ['app_id', 'app_secret']);
        render(<SettingsGuide section={s} />);
        expect(screen.getByRole('heading', { name: t('guides.ui.title') })).toBeInTheDocument();
        expect(screen.getByText('https://growth.imagina.cloud/oauth/tiktok/callback')).toBeVisible();
        expect(screen.getByRole('button', { name: t('guides.ui.hide') })).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(t('guides.platform.tiktok.steps.keys.title'))).toBeVisible();
        const link = screen.getAllByRole('link')[0];
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('si la sección ya está lista empieza plegada y se abre con un clic', () => {
        const s = section('ai', ['api_key', 'monthly_budget_usd'], { configured: true });
        render(<SettingsGuide section={s} />);
        const toggle = screen.getByRole('button', { name: t('guides.ui.show') });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByText(t('guides.platform.ai.steps.key.title'))).not.toBeVisible();
        fireEvent.click(toggle);
        expect(screen.getByText(t('guides.platform.ai.steps.key.title'))).toBeVisible();
        expect(screen.getByText(t('guides.ui.progress').replace('{{done}}', '3').replace('{{total}}', '3'))).toBeInTheDocument();
    });

    it('una sección sin credenciales de terceros no muestra guía', () => {
        const { container } = render(<SettingsGuide section={section('retention', [])} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('la política de AWS incompleta lo avisa', () => {
        const s = section('ses', [], { configured: false, guide: { iam_policy: '{"a": "<región>"}', policies_complete: false } });
        render(<SettingsGuide section={s} />);
        expect(screen.getByText(t('guides.valuesHelp.incomplete'))).toBeVisible();
    });

    it('cada valor que muestra una guía tiene su nombre', () => {
        const used = new Set<GuideValueKey>(GUIDE_SECTIONS.flatMap((k) => PLATFORM_GUIDES[k].steps.flatMap((s) => s.values ?? [])));
        expect([...used].every((k) => typeof guidesJson.guides.values[k] === 'string')).toBe(true);
    });
});

describe('guías de Conexiones', () => {
    it.each(CONNECTION_GUIDES)('«Antes de conectar» de %s tiene lo que necesitas y lo que suele fallar', (card) => {
        const base = `guides.connections.${card}`;
        expect(textList(list(`${base}.need`)).length).toBeGreaterThan(0);
        const problems = problemList(list(`${base}.problems`));
        expect(problems.length).toBeGreaterThan(0);
        for (const p of problems) {
            expect(p.problem.trim()).not.toBe('');
            expect(p.fix.trim()).not.toBe('');
        }
    });

    it('los textos no traen tarjetas que no existen', () => {
        expect(Object.keys(guidesJson.guides.connections).sort()).toEqual([...CONNECTION_GUIDES].sort());
    });

    it('se pliega y se abre en la hoja de la tarjeta', () => {
        render(<ConnectionGuide card="whatsapp" />);
        const details = screen.getByText(t('guides.ui.connection.title')).closest('details');
        expect(details).not.toHaveAttribute('open');
        expect(screen.getByText(t('guides.ui.connection.need'))).not.toBeVisible();
        fireEvent.click(screen.getByText(t('guides.ui.connection.title')));
        expect(details).toHaveAttribute('open');
    });

    it('la tarjeta sin guía no muestra nada', () => {
        const { container } = render(<ConnectionGuide card="capture" />);
        expect(container).toBeEmptyDOMElement();
    });
});
