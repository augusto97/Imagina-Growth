import { MessageCircle, X } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { consentsForFields, consentText, fillPlaceholders, isPrizes, nextQuestion } from './model';
import type { ConsentChannel, CouponConfig, OnsiteOptions, Quiz, WidgetContent, WidgetDesign, WidgetField, WidgetKind } from './types';

/*
 * Vista previa en vivo del widget sobre una página de ejemplo (escritorio o celular).
 * Es una aproximación del paquete de widgets del tracker: misma estructura, colores
 * y textos; la tienda real usa su propia tipografía.
 */

export type PreviewDevice = 'desktop' | 'mobile';
export type PreviewState = 'form' | 'step2' | 'success' | 'quiz';

export interface PreviewProps {
    kind: WidgetKind;
    design: WidgetDesign;
    content: WidgetContent;
    channels: ConsentChannel[];
    coupon: CouponConfig;
    quiz: Quiz | null;
    options: OnsiteOptions | undefined;
    device: PreviewDevice;
    state: PreviewState;
}

export function Preview(props: PreviewProps) {
    const { t } = useTranslation();
    const mobile = props.device === 'mobile';
    return (
        <div
            className={cn('igr:relative igr:mx-auto igr:overflow-hidden igr:rounded-lg igr:border igr:bg-background igr:shadow-sm', mobile ? 'igr:h-[560px] igr:w-[300px]' : 'igr:h-[420px] igr:w-full')}
            role="img"
            aria-label={t('onsite.preview.aria', { kind: t(`onsite.kinds.${props.kind}.name`) })}
        >
            <FakePage mobile={mobile} />
            <Placement {...props} />
        </div>
    );
}

function FakePage({ mobile }: { mobile: boolean }) {
    return (
        <div className="igr:absolute igr:inset-0 igr:p-3" aria-hidden>
            <div className="igr:mb-3 igr:flex igr:items-center igr:gap-1.5">
                <span className="igr:size-2 igr:rounded-full igr:bg-muted-foreground/30" />
                <span className="igr:size-2 igr:rounded-full igr:bg-muted-foreground/30" />
                <span className="igr:size-2 igr:rounded-full igr:bg-muted-foreground/30" />
                <span className="igr:ml-2 igr:h-4 igr:flex-1 igr:rounded igr:bg-muted" />
            </div>
            <div className="igr:mb-3 igr:h-6 igr:w-28 igr:rounded igr:bg-muted" />
            <div className={cn('igr:grid igr:gap-3', mobile ? 'igr:grid-cols-2' : 'igr:grid-cols-4')}>
                {Array.from({ length: mobile ? 4 : 8 }, (_, i) => (
                    <div key={i} className="igr:space-y-1.5">
                        <div className="igr:aspect-square igr:rounded igr:bg-muted" />
                        <div className="igr:h-2 igr:w-3/4 igr:rounded igr:bg-muted" />
                        <div className="igr:h-2 igr:w-1/2 igr:rounded igr:bg-muted" />
                    </div>
                ))}
            </div>
        </div>
    );
}

function Placement(props: PreviewProps) {
    const { kind, design, device } = props;
    const mobile = device === 'mobile';
    const position = design.position ?? '';
    const theme = design.theme;
    const card: CSSProperties = { background: theme.background, color: theme.text };

    if (kind === 'wa_widget') {
        return (
            <div className={cn('igr:absolute igr:bottom-3 igr:flex igr:items-end igr:gap-2', position === 'left' ? 'igr:left-3 igr:flex-row-reverse' : 'igr:right-3')}>
                {props.content.title ? (
                    <div className="igr:max-w-[180px] igr:rounded-lg igr:bg-white igr:px-3 igr:py-2 igr:text-xs igr:text-gray-900 igr:shadow-md">{props.content.title}</div>
                ) : null}
                <div className="igr:flex igr:size-12 igr:items-center igr:justify-center igr:rounded-full igr:shadow-lg" style={{ background: theme.accent, color: theme.accent_text }}>
                    <MessageCircle className="igr:size-6" />
                </div>
            </div>
        );
    }
    if (kind === 'bar') {
        return (
            <div className={cn('igr:absolute igr:inset-x-0 igr:px-3 igr:py-2 igr:shadow', position === 'bottom' ? 'igr:bottom-0' : 'igr:top-0')} style={card}>
                <WidgetBody {...props} compact />
            </div>
        );
    }
    if (kind === 'teaser') {
        return (
            <div className={cn('igr:absolute igr:bottom-3 igr:w-60 igr:rounded-lg igr:p-3 igr:shadow-lg', position === 'right' ? 'igr:right-3' : 'igr:left-3')} style={card}>
                <WidgetBody {...props} />
            </div>
        );
    }
    if (kind === 'embedded_form' || (kind === 'quiz' && design.display === 'embedded')) {
        return (
            <div className="igr:absolute igr:inset-x-3 igr:bottom-3 igr:rounded-lg igr:border igr:p-4" style={card}>
                <WidgetBody {...props} />
            </div>
        );
    }
    // Popups (centro, esquina o pantalla completa), ruleta, 2 pasos, quiz y referido.
    const corner = position === 'corner';
    const full = position === 'fullscreen';
    return (
        <div className={cn('igr:absolute igr:inset-0 igr:flex', !corner && 'igr:bg-black/40', corner ? 'igr:items-end igr:justify-end igr:p-3' : 'igr:items-center igr:justify-center', full && 'igr:p-0')}>
            <div
                className={cn(
                    'igr:relative igr:overflow-y-auto igr:rounded-xl igr:shadow-xl',
                    full ? 'igr:size-full igr:rounded-none igr:p-6' : corner ? 'igr:w-64 igr:p-4' : mobile ? 'igr:mx-3 igr:max-h-[90%] igr:w-full igr:p-5' : 'igr:max-h-[90%] igr:w-[380px] igr:p-6',
                )}
                style={card}
            >
                <X className="igr:absolute igr:top-2.5 igr:right-2.5 igr:size-4 igr:opacity-60" aria-hidden />
                {design.image_url && design.layout !== 'text_only' ? <img src={design.image_url} alt="" className="igr:mb-3 igr:h-24 igr:w-full igr:rounded-md igr:object-cover" /> : null}
                <WidgetBody {...props} />
            </div>
        </div>
    );
}

function WidgetBody({ kind, content, channels, coupon, quiz, options, state, design, compact = false }: PreviewProps & { compact?: boolean }) {
    const { t } = useTranslation();
    const theme = design.theme;
    const button: CSSProperties = { background: theme.accent, color: theme.accent_text };
    const sample = { codigo: 'BIENVENIDA-7K2Q9X', premio: isPrizes(coupon) ? (coupon.prizes[0]?.label ?? '') : '' };

    if (state === 'success') {
        const text = kind === 'quiz' ? content.success : fillPlaceholders(content.success, sample);
        return (
            <div className="igr:text-center" role="status">
                <p className={cn('igr:font-semibold', compact ? 'igr:text-sm' : 'igr:text-lg')}>{text || t('onsite.preview.thanks')}</p>
                {kind === 'quiz' ? (
                    <div className="igr:mt-3 igr:grid igr:grid-cols-2 igr:gap-2">
                        {[0, 1].map((i) => (
                            <div key={i} className="igr:rounded-md igr:border igr:border-current/10 igr:p-2 igr:text-left igr:text-xs">
                                <div className="igr:mb-1 igr:aspect-square igr:rounded igr:bg-black/5" />
                                {t('onsite.preview.product', { n: i + 1 })}
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>
        );
    }

    if (kind === 'quiz' && state === 'quiz' && quiz) {
        const first = quiz.questions[0];
        const second = first && first.options[0] ? nextQuestion(quiz, first, first.options[0].key) : null;
        return (
            <div>
                <p className="igr:mb-1 igr:text-xs igr:opacity-70">{t('onsite.preview.question', { n: 1, total: quiz.questions.length })}</p>
                <p className="igr:mb-3 igr:font-semibold">{first?.text ?? ''}</p>
                <div className="igr:space-y-2">
                    {first?.options.map((o) => (
                        <div key={o.key} className="igr:rounded-md igr:border igr:border-current/20 igr:px-3 igr:py-2 igr:text-sm">
                            {o.label}
                        </div>
                    ))}
                </div>
                {second ? <p className="igr:mt-3 igr:text-xs igr:opacity-60">{t('onsite.preview.nextIs', { text: second.text })}</p> : null}
            </div>
        );
    }

    const step = state === 'step2' && content.step2 ? content.step2 : null;
    const title = step ? step.title : content.title;
    const body = step ? step.body : content.body;
    const fields = step ? step.fields : content.fields;
    const cta = step ? step.cta : kind === 'quiz' ? content.start || content.cta : content.cta;
    const shownChannels = consentsForFields(channels, fields);
    const wheel = kind === 'spin_wheel' && isPrizes(coupon) ? coupon.prizes : null;

    if (compact) {
        return (
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-center igr:gap-2 igr:text-xs">
                <span className="igr:font-medium">{title}</span>
                {fields.slice(0, 1).map((f) => (
                    <FakeInput key={f.key} field={f} small />
                ))}
                <span className="igr:rounded igr:px-2.5 igr:py-1 igr:font-semibold" style={button}>
                    {cta}
                </span>
            </div>
        );
    }

    return (
        <div>
            {wheel ? <Wheel labels={wheel.map((p) => p.label)} accent={theme.accent} text={theme.accent_text} /> : null}
            <p className="igr:text-lg igr:leading-snug igr:font-semibold">{title}</p>
            {body ? <p className="igr:mt-1 igr:text-sm igr:opacity-80">{body}</p> : null}
            {kind !== 'quiz' || state !== 'form' || fields.length > 0 ? (
                <div className="igr:mt-3 igr:space-y-2">
                    {fields.map((f) => (
                        <FakeInput key={f.key} field={f} />
                    ))}
                    {shownChannels.map((c) => {
                        const text = consentText(options, c);
                        return (
                            <label key={c} className="igr:flex igr:items-start igr:gap-2 igr:text-[11px] igr:leading-snug igr:opacity-80">
                                <span className="igr:mt-0.5 igr:size-3 igr:shrink-0 igr:rounded-sm igr:border igr:border-current" aria-hidden />
                                <span>{text?.label ?? t(`onsite.channels.${c}`)}</span>
                            </label>
                        );
                    })}
                </div>
            ) : null}
            <div className="igr:mt-3 igr:rounded-md igr:py-2 igr:text-center igr:text-sm igr:font-semibold" style={button}>
                {cta || '—'}
            </div>
            {step?.skip ? <p className="igr:mt-2 igr:text-center igr:text-xs igr:underline igr:opacity-70">{step.skip}</p> : null}
            {content.footer && !step ? <p className="igr:mt-2 igr:text-[11px] igr:opacity-60">{content.footer}</p> : null}
        </div>
    );
}

function FakeInput({ field, small = false }: { field: WidgetField; small?: boolean }): ReactNode {
    const { t } = useTranslation();
    return (
        <div className={cn('igr:rounded-md igr:border igr:border-current/25 igr:bg-white/60 igr:text-gray-500', small ? 'igr:px-2 igr:py-0.5' : 'igr:px-3 igr:py-2 igr:text-sm')}>
            {field.label || t(`onsite.fields.${field.key}`)}
            {field.required ? ' *' : ''}
        </div>
    );
}

/** Ruleta decorativa: solo las etiquetas de los premios (las probabilidades no se muestran). */
function Wheel({ labels, accent, text }: { labels: string[]; accent: string; text: string }) {
    const n = Math.max(2, labels.length);
    const r = 70;
    const slices = Array.from({ length: n }, (_, i) => {
        const a0 = (i / n) * 2 * Math.PI - Math.PI / 2;
        const a1 = ((i + 1) / n) * 2 * Math.PI - Math.PI / 2;
        const mid = (a0 + a1) / 2;
        const large = a1 - a0 > Math.PI ? 1 : 0;
        return {
            d: `M0,0 L${r * Math.cos(a0)},${r * Math.sin(a0)} A${r},${r} 0 ${large} 1 ${r * Math.cos(a1)},${r * Math.sin(a1)} Z`,
            x: 0.62 * r * Math.cos(mid),
            y: 0.62 * r * Math.sin(mid),
            label: labels[i] ?? '',
            even: i % 2 === 0,
        };
    });
    return (
        <svg viewBox="-75 -75 150 150" className="igr:mx-auto igr:mb-3 igr:size-36" aria-hidden>
            {slices.map((s, i) => (
                <g key={i}>
                    <path d={s.d} fill={accent} fillOpacity={s.even ? 1 : 0.55} stroke="#ffffff" strokeWidth={2} />
                    <text x={s.x} y={s.y} textAnchor="middle" dominantBaseline="middle" fontSize={9} fontWeight={600} fill={s.even ? text : '#111827'}>
                        {s.label.slice(0, 12)}
                    </text>
                </g>
            ))}
            <circle r={9} fill="#ffffff" stroke={accent} strokeWidth={2} />
        </svg>
    );
}
