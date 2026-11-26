import type { SettingsSection } from '@/features/platform/settings-api';

/*
 * Guías paso a paso (docs/guias-conexiones.md). Lógica pura con pruebas: qué pasos tiene cada guía, qué valores del hub se
 * copian en cada paso y qué pasos ya se ven hechos con lo guardado. Los textos viven en `locales/es-CO/guides.json`:
 * `guides.platform.<sección>` (intro, before, steps.<paso>.title/body, problems, check) y `guides.connections.<tarjeta>`.
 */

/** Las secciones de Plataforma → Ajustes que piden credenciales de un tercero (las mismas que exige la prueba del hub). */
export const GUIDE_SECTIONS = ['mail', 'ses', 'meta', 'google', 'tiktok', 'ai', 'monitor'] as const;
export type GuideSection = (typeof GUIDE_SECTIONS)[number];

/** Enlaces a las consolas: el texto del botón va en `guides.links.<clave>`. */
export const GUIDE_LINKS = {
    metaBusiness: 'https://business.facebook.com/settings',
    metaSecurity: 'https://business.facebook.com/settings/security',
    metaApps: 'https://developers.facebook.com/apps',
    metaTechProvider: 'https://developers.facebook.com/docs/whatsapp/solution-providers/get-started-for-tech-providers',
    metaLoginBusiness: 'https://developers.facebook.com/docs/facebook-login/facebook-login-for-business',
    metaDataDeletion: 'https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback',
    metaLeadTest: 'https://developers.facebook.com/tools/lead-ads-testing',
    googleConsole: 'https://console.cloud.google.com/projectcreate',
    googleLibrary: 'https://console.cloud.google.com/apis/library',
    googleBranding: 'https://console.cloud.google.com/auth/branding',
    googleAudience: 'https://console.cloud.google.com/auth/audience',
    googleDataAccess: 'https://console.cloud.google.com/auth/scopes',
    googleClients: 'https://console.cloud.google.com/auth/clients',
    googleSearchConsole: 'https://search.google.com/search-console',
    googleAdsAccess: 'https://developers.google.com/google-ads/api/docs/access-levels',
    googleVerification: 'https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification',
    tiktokPortal: 'https://business-api.tiktok.com/portal',
    awsSes: 'https://console.aws.amazon.com/ses/home',
    awsSns: 'https://console.aws.amazon.com/sns/v3/home',
    awsIam: 'https://console.aws.amazon.com/iam/home#/users',
    awsSandbox: 'https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html',
    emailitSmtp: 'https://emailit.com/docs/guides/sending-using-smtp/',
    brevoSmtp: 'https://app.brevo.com/settings/keys/smtp',
    googleAppPasswords: 'https://myaccount.google.com/apppasswords',
    anthropicKeys: 'https://platform.claude.com/settings/keys',
    anthropicWorkspaces: 'https://platform.claude.com/settings/workspaces',
    anthropicBilling: 'https://platform.claude.com/settings/billing',
    betterStack: 'https://uptime.betterstack.com',
    betterStackDocs: 'https://betterstack.com/docs/uptime/cron-and-heartbeat-monitor/',
} as const;
export type GuideLinkKey = keyof typeof GUIDE_LINKS;

/** Valores que el hub calcula para copiar (`section.info.guide.<clave>`); su nombre va en `guides.values.<clave>`. */
export type GuideValueKey =
    | 'site_url'
    | 'domain'
    | 'callback_url'
    | 'webhook_url'
    | 'whatsapp_fields'
    | 'page_fields'
    | 'instagram_fields'
    | 'whatsapp_permissions'
    | 'login_permissions'
    | 'authorized_domain'
    | 'scopes'
    | 'legacy_callback_url'
    | 'configuration_set'
    | 'iam_policy'
    | 'sns_policy_statement';

/** Lo que ya se comprueba con lo guardado, además de los campos del paso. */
type GuideCheck = (section: SettingsSection) => boolean;

export interface GuideStep {
    id: string;
    links?: GuideLinkKey[];
    values?: GuideValueKey[];
    /** Campos de la sección que este paso llena: el paso queda listo cuando todos están configurados. */
    fields?: string[];
    /** Otra señal de que el paso está hecho (una cuenta conectada, el configuration set listo…). */
    check?: GuideCheck;
}

export interface PlatformGuide {
    section: GuideSection;
    /** Minutos aproximados sin contar las revisiones de Meta, Google o TikTok. */
    minutes: number;
    steps: GuideStep[];
}

const accountsConnected: GuideCheck = (s) => (s.info.connected_accounts ?? 0) > 0;
const sesReady: GuideCheck = (s) => s.info.last_setup?.status === 'ok';
const sectionOn: GuideCheck = (s) => s.info.configured === true;

export const PLATFORM_GUIDES: Record<GuideSection, PlatformGuide> = {
    mail: {
        section: 'mail',
        minutes: 15,
        steps: [
            { id: 'provider', links: ['emailitSmtp', 'brevoSmtp', 'googleAppPasswords'] },
            { id: 'domain' },
            { id: 'server', fields: ['smtp_host', 'smtp_port', 'smtp_encryption'] },
            { id: 'login', fields: ['smtp_username', 'smtp_password'] },
            { id: 'sender', fields: ['from_address', 'from_name'], check: sectionOn },
            { id: 'test' },
        ],
    },
    ses: {
        section: 'ses',
        minutes: 30,
        steps: [
            { id: 'region', links: ['awsSes'], fields: ['region'] },
            { id: 'identity', links: ['awsSes'] },
            { id: 'sandbox', links: ['awsSandbox'] },
            { id: 'topic', links: ['awsSns'], values: ['webhook_url', 'sns_policy_statement'], fields: ['sns_topic_arn'] },
            { id: 'user', links: ['awsIam'], values: ['iam_policy', 'configuration_set'], fields: ['key', 'secret'] },
            { id: 'prepare', check: sesReady },
        ],
    },
    meta: {
        section: 'meta',
        minutes: 60,
        steps: [
            { id: 'legal', links: ['metaDataDeletion'] },
            { id: 'business', links: ['metaBusiness', 'metaSecurity'] },
            { id: 'app', links: ['metaApps'], values: ['site_url', 'domain'], fields: ['app_id', 'app_secret'] },
            { id: 'login', values: ['domain', 'callback_url'], links: ['metaLoginBusiness'] },
            { id: 'whatsapp', values: ['whatsapp_permissions'], fields: ['es_config_id'], links: ['metaTechProvider'] },
            { id: 'ads', values: ['login_permissions'], fields: ['login_config_id'] },
            { id: 'webhooks', values: ['webhook_url', 'whatsapp_fields', 'page_fields', 'instagram_fields'], fields: ['webhook_verify_token'] },
            { id: 'review', links: ['metaLeadTest'] },
            { id: 'live', check: accountsConnected },
        ],
    },
    google: {
        section: 'google',
        minutes: 45,
        steps: [
            { id: 'project', links: ['googleConsole', 'googleLibrary'] },
            { id: 'branding', links: ['googleBranding', 'googleSearchConsole'], values: ['authorized_domain', 'site_url'] },
            { id: 'audience', links: ['googleAudience'] },
            { id: 'scopes', links: ['googleDataAccess'], values: ['scopes'] },
            { id: 'client', links: ['googleClients'], values: ['callback_url', 'legacy_callback_url'], fields: ['client_id', 'client_secret'] },
            { id: 'ads', links: ['googleAdsAccess'] },
            { id: 'verification', links: ['googleVerification'] },
        ],
    },
    tiktok: {
        section: 'tiktok',
        minutes: 20,
        steps: [
            { id: 'developer', links: ['tiktokPortal'] },
            { id: 'app', links: ['tiktokPortal'], values: ['callback_url'] },
            { id: 'review' },
            { id: 'keys', fields: ['app_id', 'app_secret'] },
        ],
    },
    ai: {
        section: 'ai',
        minutes: 10,
        steps: [
            { id: 'account', links: ['anthropicBilling'] },
            { id: 'key', links: ['anthropicKeys'], fields: ['api_key'] },
            { id: 'limit', links: ['anthropicWorkspaces'], fields: ['monthly_budget_usd'] },
            { id: 'enable', check: sectionOn },
        ],
    },
    monitor: {
        section: 'monitor',
        minutes: 10,
        steps: [
            { id: 'account', links: ['betterStack', 'betterStackDocs'] },
            { id: 'heartbeats', fields: ['heartbeat_health', 'heartbeat_streams', 'heartbeat_queues'] },
            { id: 'alerts' },
        ],
    },
};

export function isGuideSection(key: string): key is GuideSection {
    return (GUIDE_SECTIONS as readonly string[]).includes(key);
}

export type GuideStepStatus = 'done' | 'todo' | 'unknown';

/**
 * El estado de cada paso con lo que está guardado: listo si todos sus campos están configurados (y su señal propia, si
 * la tiene, también), pendiente si falta algo, y sin estado si desde aquí no se puede saber (pasos que se hacen solo en
 * la consola del proveedor).
 */
export function stepStatus(step: GuideStep, section: SettingsSection): GuideStepStatus {
    const fields = step.fields ?? [];
    if (fields.length === 0 && !step.check) return 'unknown';
    const filled = fields.every((key) => section.fields.find((f) => f.key === key)?.configured === true);
    const checked = step.check ? step.check(section) : true;
    return filled && checked ? 'done' : 'todo';
}

export interface GuideProgress {
    statuses: GuideStepStatus[];
    done: number;
    /** Pasos que se pueden comprobar desde el panel. */
    total: number;
    complete: boolean;
}

export function guideProgress(guide: PlatformGuide, section: SettingsSection): GuideProgress {
    const statuses = guide.steps.map((s) => stepStatus(s, section));
    const total = statuses.filter((s) => s !== 'unknown').length;
    const done = statuses.filter((s) => s === 'done').length;
    return { statuses, done, total, complete: total > 0 && done === total };
}

export type GuideValue = { key: GuideValueKey; text: string; list: string[] | null; multiline: boolean };

/**
 * Los valores de un paso tal como los calculó el hub (`info.guide`); el que no vino (p. ej. la dirección del cliente
 * viejo de la agenda) no se muestra. Una lista se copia una por línea y se ve como chips.
 */
export function stepValues(step: GuideStep, section: SettingsSection): GuideValue[] {
    const source = section.info.guide ?? {};
    const out: GuideValue[] = [];
    for (const key of step.values ?? []) {
        const raw = source[key];
        if (Array.isArray(raw)) {
            const list = raw.filter((v): v is string => typeof v === 'string' && v !== '');
            if (list.length > 0) out.push({ key, text: list.join('\n'), list, multiline: false });
        } else if (typeof raw === 'string' && raw !== '') {
            out.push({ key, text: raw, list: null, multiline: raw.includes('\n') });
        }
    }
    return out;
}

/** Abierta si la sección todavía no funciona; cerrada si ya está lista (se abre con un clic). */
export function guideStartsOpen(section: SettingsSection, progress: GuideProgress): boolean {
    if (section.info.configured === true) return false;
    if (section.info.configured === false) return true;
    return !progress.complete;
}

/* ── Conexiones de cada espacio de trabajo ─────────────────────────────────────────────────────────────────────────── */

/**
 * Tarjetas de Conexiones (`CardKey` de features/connections/hub.ts) con una guía «Antes de conectar»
 * (`guides.connections.<tarjeta>`: need, steps, notes, problems). Sin importar el tipo, así Plataforma no arrastra los
 * textos de Conexiones.
 */
export const CONNECTION_GUIDES = ['web', 'whatsapp', 'messenger', 'instagram', 'email', 'sms', 'meta', 'google', 'tiktok', 'calendar'] as const;
export type ConnectionGuideCard = (typeof CONNECTION_GUIDES)[number];

export function hasConnectionGuide(card: string): card is ConnectionGuideCard {
    return (CONNECTION_GUIDES as readonly string[]).includes(card);
}

/** Problema y arreglo de «Si algo falla». */
export interface GuideProblem {
    problem: string;
    fix: string;
}

/** Lee del archivo de textos una lista (de textos o de problemas), sin romper si falta o vino con otra forma. */
export function textList(raw: unknown): string[] {
    return Array.isArray(raw) ? raw.filter((v): v is string => typeof v === 'string' && v.trim() !== '') : [];
}

export function problemList(raw: unknown): GuideProblem[] {
    if (!Array.isArray(raw)) return [];
    return raw.filter(
        (v): v is GuideProblem => typeof v === 'object' && v !== null && typeof (v as GuideProblem).problem === 'string' && typeof (v as GuideProblem).fix === 'string',
    );
}
