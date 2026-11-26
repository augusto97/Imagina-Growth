import { Check, MessageCircle } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';
import type { PreviewDevice } from '@/components/ui/device-frame';
import { consentsAfter, DEFAULT_ACCENT, fieldType, isHexColor, paragraphs, readableOn, type LandingDraft } from './model';
import type { CaptureOptions, ConsentChannel, LandingField } from './types';

/*
 * Vista previa en vivo de la página de captura: la misma estructura, los colores y los textos de la página pública
 * (resources/views/capture/page.blade.php y layout.blade.php), pintada con `device` y no con las consultas de medios del
 * panel. La página del cliente siempre es clara, así que aquí los colores son los suyos y no los tokens del panel.
 */

const PAGE = { fg: '#0f172a', muted: '#475569', bg: '#f8fafc', card: '#ffffff', line: '#dbe2ea', field: '#c5cfdb' } as const;

export type PreviewState = 'form' | 'thanks';

export function LandingPreview({
    draft,
    device,
    state,
    business,
    logo,
    image,
    options,
}: {
    draft: LandingDraft;
    device: PreviewDevice;
    state: PreviewState;
    business: string;
    logo: string | null;
    image: string | null;
    options: CaptureOptions | undefined;
}) {
    const { t } = useTranslation();
    const mobile = device === 'mobile';
    const accent = isHexColor(draft.content.color) ? draft.content.color : DEFAULT_ACCENT;
    const accentFg = readableOn(accent);
    const c = draft.content;
    const root: CSSProperties = {
        color: PAGE.fg,
        background: PAGE.bg,
        backgroundImage: `radial-gradient(900px 420px at 0% -10%, ${accent}29, transparent 70%), radial-gradient(700px 380px at 110% 0%, ${accent}1a, transparent 70%)`,
        minHeight: '100%',
        fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        fontSize: mobile ? 15 : 14,
        lineHeight: 1.55,
        padding: mobile ? '16px 14px 28px' : '18px 22px 28px',
    };
    const card: CSSProperties = {
        background: PAGE.card,
        border: `1px solid ${PAGE.line}`,
        borderRadius: 14,
        padding: mobile ? '18px 16px' : '18px 18px',
        boxShadow: '0 1px 2px rgba(15,23,42,.04), 0 12px 32px -12px rgba(15,23,42,.18)',
    };
    const button: CSSProperties = {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 8,
        width: '100%',
        minHeight: mobile ? 48 : 42,
        borderRadius: 10,
        background: accent,
        color: accentFg,
        fontWeight: 700,
        fontSize: mobile ? 16 : 14,
        border: 0,
    };
    const header = (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, minHeight: 36, marginBottom: 14, fontWeight: 700, fontSize: mobile ? 16 : 15 }}>
            {logo ? <img src={logo} alt="" referrerPolicy="no-referrer" style={{ maxHeight: 38, maxWidth: 170, objectFit: 'contain' }} /> : <span>{business}</span>}
        </div>
    );

    if (state === 'thanks') {
        return (
            <div style={root}>
                {header}
                <div style={{ ...card, textAlign: 'center', padding: '30px 20px', maxWidth: 460, margin: '0 auto' }}>
                    <span
                        style={{
                            width: 52,
                            height: 52,
                            borderRadius: 999,
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            background: `${accent}24`,
                            color: accent,
                            marginBottom: 8,
                        }}
                    >
                        <Check size={26} aria-hidden />
                    </span>
                    <p style={{ fontSize: 20, fontWeight: 700, margin: '4px 0 6px' }}>{c.success_title || t('capture.preview.thanksTitle')}</p>
                    <p style={{ color: PAGE.muted, margin: 0, whiteSpace: 'pre-line' }}>{c.success_message || t('capture.preview.thanksBody')}</p>
                    {c.whatsapp.enabled ? (
                        <span style={{ ...button, background: '#fff', color: '#075e54', border: '1px solid #b7e4cf', marginTop: 16 }}>
                            <MessageCircle size={18} aria-hidden />
                            {c.whatsapp.label || t('capture.preview.whatsapp')}
                        </span>
                    ) : null}
                </div>
            </div>
        );
    }

    const lead = (
        <section>
            <h1 style={{ fontSize: mobile ? 27 : 26, lineHeight: 1.12, letterSpacing: '-.02em', margin: '2px 0 12px', fontWeight: 700 }}>
                {c.title || t('capture.preview.untitled')}
            </h1>
            {paragraphs(c.body).map((p, i) => (
                <p key={i} style={{ margin: '0 0 10px', color: PAGE.muted, fontSize: mobile ? 16 : 14.5, whiteSpace: 'pre-line' }}>
                    {p}
                </p>
            ))}
        </section>
    );
    const media = image ? (
        <img
            src={image}
            alt=""
            referrerPolicy="no-referrer"
            style={{ width: '100%', aspectRatio: '16 / 10', objectFit: 'cover', borderRadius: 14, background: '#e2e8f0', display: 'block', marginTop: mobile ? 0 : 8 }}
        />
    ) : null;
    const form = (
        <div style={card}>
            {draft.fields.map((field) => (
                <PreviewField key={field.key} field={field} consents={consentsAfter(field, draft.consent_channels)} options={options} mobile={mobile} />
            ))}
            <div style={{ marginTop: 18 }}>
                <span style={button}>{c.button || t('capture.preview.send')}</span>
            </div>
            <p style={{ color: PAGE.muted, fontSize: 12, margin: '14px 0 0' }}>
                {t('capture.preview.legal', { business })} {t('capture.preview.rights')}{' '}
                {options?.privacy_policy_url ? <span style={{ textDecoration: 'underline' }}>{t('capture.preview.privacy')}</span> : null}
            </p>
            {c.whatsapp.enabled ? (
                <>
                    <p style={{ textAlign: 'center', color: PAGE.muted, fontSize: 13, margin: '12px 0 0' }}>{t('capture.preview.or')}</p>
                    <span style={{ ...button, background: '#fff', color: '#075e54', border: '1px solid #b7e4cf', marginTop: 10 }}>
                        <MessageCircle size={18} aria-hidden />
                        {c.whatsapp.label || t('capture.preview.whatsapp')}
                    </span>
                </>
            ) : null}
        </div>
    );

    return (
        <div style={root}>
            {header}
            {mobile ? (
                <div style={{ display: 'grid', gap: 18 }}>
                    {lead}
                    {form}
                    {media}
                </div>
            ) : (
                <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1.05fr) minmax(0, .95fr)', columnGap: 26, alignItems: 'start' }}>
                    <div>
                        {lead}
                        {media}
                    </div>
                    {form}
                </div>
            )}
        </div>
    );
}

function PreviewField({ field, consents, options, mobile }: { field: LandingField; consents: ConsentChannel[]; options: CaptureOptions | undefined; mobile: boolean }) {
    const { t } = useTranslation();
    const type = fieldType(field);
    const input: CSSProperties = {
        width: '100%',
        minHeight: mobile ? 44 : 38,
        borderRadius: 10,
        border: `1px solid ${PAGE.field}`,
        background: '#fff',
        padding: '8px 12px',
        color: '#94a3b8',
        fontSize: mobile ? 15 : 13.5,
        display: 'flex',
        alignItems: 'center',
    };
    const check = (label: string, key: string) => (
        <span key={key} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 12.5, color: PAGE.muted, margin: '8px 0 0', lineHeight: 1.45 }}>
            <span style={{ width: 16, height: 16, flex: 'none', marginTop: 1, borderRadius: 4, border: `1.5px solid ${PAGE.field}`, background: '#fff' }} />
            <span>{label}</span>
        </span>
    );
    return (
        <div style={{ marginTop: 12 }}>
            {type === 'checkbox' ? (
                check(`${field.label}${field.required ? ` (${t('capture.preview.required')})` : ''}`, 'self')
            ) : (
                <>
                    <span style={{ display: 'block', fontWeight: 600, fontSize: mobile ? 14.5 : 13.5, margin: '0 0 5px' }}>
                        {field.label}
                        {!field.required ? <span style={{ color: PAGE.muted, fontWeight: 400, fontSize: 12 }}> ({t('capture.preview.optional')})</span> : null}
                    </span>
                    <span
                        style={{
                            ...input,
                            minHeight: type === 'textarea' ? 72 : input.minHeight,
                            alignItems: type === 'textarea' ? 'flex-start' : 'center',
                            justifyContent: 'space-between',
                        }}
                    >
                        <span>{field.placeholder ?? (type === 'select' ? t('capture.preview.choose') : '')}</span>
                        {type === 'select' ? <span style={{ color: PAGE.muted }}>▾</span> : null}
                    </span>
                </>
            )}
            {consents.map((channel) => check(options?.consent_texts.find((c) => c.channel === channel)?.label ?? t(`capture.channels.${channel}`), channel))}
        </div>
    );
}
