import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FlowOptions } from '../../types';

/*
 * Nodo «Reactivar con IA» (auditoría §5.1 función 7): la IA escribe según la última
 * conversación y las compras; dentro de la ventana de 24 h sale como texto y fuera, en la
 * plantilla de marketing con el texto en la variable `reactivacion.mensaje`. Las reglas y la
 * validación son del servidor (AiReactivateHandler, GraphValidator).
 */

type Config = Record<string, unknown>;

const CHANNELS = ['whatsapp', 'email'] as const;

const list = (v: unknown): string[] => (Array.isArray(v) ? v.map((x) => String(x)) : []);
const str = (v: unknown, fallback = '') => (typeof v === 'string' ? v : typeof v === 'number' ? String(v) : fallback);

export const AI_REACTIVATE_DEFAULTS = (): Config => ({ channels: ['whatsapp', 'email'], lookback_days: 365, offer: '' });

/** Una línea para la tarjeta del nodo: los canales en orden y si lleva oferta. */
export function aiReactivateSummary(t: TFunction, c: Config): string {
    const channels = list(c.channels)
        .map((ch) => t(`flows.channels.${ch}`))
        .join(' → ');
    return str(c.offer).trim() !== '' ? t('reactivation.summaryOffer', { channels }) : t('reactivation.summary', { channels });
}

export function AiReactivateConfig({
    nodeId,
    config,
    options,
    readOnly,
    onChange,
}: {
    nodeId: string;
    config: Config;
    options: FlowOptions;
    readOnly: boolean;
    onChange: (patch: Config) => void;
}) {
    const { t } = useTranslation();
    const channels = list(config.channels);
    const id = (name: string) => `node-${nodeId}-${name}`;
    const templates = options.templates.filter((tpl) => tpl.channel === 'whatsapp' && tpl.category === 'marketing');

    return (
        <>
            <p className="igr:text-[12px] igr:text-muted-foreground">{t('reactivation.help')}</p>
            <fieldset className="igr:space-y-1">
                <legend className="igr:text-[13px] igr:font-medium">{t('reactivation.channels')}</legend>
                {CHANNELS.map((ch) => (
                    <label key={ch} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input
                            type="checkbox"
                            checked={channels.includes(ch)}
                            disabled={readOnly}
                            onChange={(e) => onChange({ channels: CHANNELS.filter((x) => (x === ch ? e.target.checked : channels.includes(x))) })}
                        />
                        {t(`flows.channels.${ch}`)}
                    </label>
                ))}
            </fieldset>
            {channels.includes('whatsapp') ? (
                <div className="igr:space-y-1">
                    <Label htmlFor={id('template')}>{t('reactivation.template')}</Label>
                    <Select id={id('template')} value={str(config.template_id)} disabled={readOnly} onChange={(e) => onChange({ template_id: e.target.value || null })}>
                        <option value="">{t('reactivation.templateDefault')}</option>
                        {templates.map((tpl) => (
                            <option key={tpl.id} value={tpl.id}>
                                {tpl.name}
                            </option>
                        ))}
                    </Select>
                    <p className="igr:text-[11.5px] igr:text-muted-foreground">
                        {t('reactivation.templateHelp')} <code className="igr:rounded igr:bg-muted igr:px-1">{'{{ reactivacion.mensaje }}'}</code>
                    </p>
                </div>
            ) : null}
            <div className="igr:space-y-1">
                <Label htmlFor={id('offer')}>{t('reactivation.offer')}</Label>
                <Textarea
                    id={id('offer')}
                    rows={2}
                    maxLength={200}
                    placeholder={t('reactivation.offerPlaceholder')}
                    value={str(config.offer)}
                    disabled={readOnly}
                    onChange={(e) => onChange({ offer: e.target.value })}
                />
                <p className="igr:text-[11.5px] igr:text-muted-foreground">{t('reactivation.offerHelp')}</p>
            </div>
            <div className="igr:space-y-1">
                <Label htmlFor={id('lookback')}>{t('reactivation.lookback')}</Label>
                <Input
                    id={id('lookback')}
                    type="number"
                    min={7}
                    max={730}
                    className="igr:w-28"
                    value={str(config.lookback_days, '365')}
                    disabled={readOnly}
                    onChange={(e) => onChange({ lookback_days: Math.max(7, Math.min(730, Math.round(Number(e.target.value) || 365))) })}
                />
            </div>
        </>
    );
}
