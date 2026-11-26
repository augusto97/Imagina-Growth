import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BUILTIN_TYPES, CHANNELS, consentField, CUSTOM_TYPES, customKey, fieldType, isCustom, keepPossible, missingBuiltins, moveField, possibleChannels } from './model';
import type { CaptureOptions, ConsentChannel, LandingField } from './types';

/**
 * Los campos del formulario de la página (hasta `max_fields`, siempre con correo o WhatsApp) y las casillas de permiso
 * por canal, cada una junto a su campo y sin marcar, con el texto legal vigente (Consentimiento → Textos).
 */
export function LandingFieldsEditor({
    fields,
    channels,
    options,
    onChange,
}: {
    fields: LandingField[];
    channels: ConsentChannel[];
    options: CaptureOptions | undefined;
    onChange: (fields: LandingField[], channels: ConsentChannel[]) => void;
}) {
    const { t } = useTranslation();
    const max = options?.max_fields ?? 12;
    const missing = missingBuiltins(fields);
    const setFields = (next: LandingField[]) => onChange(next, keepPossible(channels, next));
    const update = (index: number, patch: Partial<LandingField>) => setFields(fields.map((f, i) => (i === index ? { ...f, ...patch } : f)));
    const possible = possibleChannels(fields);

    return (
        <div className="igr:space-y-5">
            <ol className="igr:space-y-2" aria-label={t('capture.fields.listLabel')}>
                {fields.map((field, index) => (
                    <FieldRow
                        key={field.key}
                        field={field}
                        index={index}
                        total={fields.length}
                        onChange={(patch) => update(index, patch)}
                        onMove={(step) => setFields(moveField(fields, index, step))}
                        onRemove={() => setFields(fields.filter((_, i) => i !== index))}
                    />
                ))}
            </ol>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" disabled={fields.length >= max}>
                        <Plus aria-hidden />
                        {t('capture.fields.add')}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="igr:w-64">
                    {missing.length > 0 ? <DropdownMenuLabel>{t('capture.fields.suggested')}</DropdownMenuLabel> : null}
                    {missing.map((key) => (
                        <DropdownMenuItem
                            key={key}
                            onSelect={() =>
                                setFields([
                                    ...fields,
                                    {
                                        key,
                                        label: t(`capture.fields.builtin.${key}`),
                                        type: BUILTIN_TYPES[key],
                                        required: key === 'phone' || key === 'name',
                                        ...(key === 'service' ? { options: [] } : {}),
                                    },
                                ])
                            }
                        >
                            {t(`capture.fields.builtin.${key}`)}
                        </DropdownMenuItem>
                    ))}
                    {missing.length > 0 ? <DropdownMenuSeparator /> : null}
                    <DropdownMenuLabel>{t('capture.fields.custom')}</DropdownMenuLabel>
                    {CUSTOM_TYPES.map((type) => (
                        <DropdownMenuItem
                            key={type}
                            onSelect={() => {
                                const label = t(`capture.fields.newLabel.${type}`);
                                setFields([...fields, { key: customKey(label, fields), label, type, required: false, ...(type === 'select' ? { options: [] } : {}) }]);
                            }}
                        >
                            {t(`capture.fields.types.${type}`)}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <p className="igr:text-caption igr:text-muted-foreground">{t('capture.fields.limit', { used: fields.length, max })}</p>

            <fieldset className="igr:space-y-3 igr:rounded-lg igr:border igr:p-4">
                <legend className="igr:px-1 igr:text-[13px] igr:font-semibold">{t('capture.consent.title')}</legend>
                <p className="igr:text-support igr:text-muted-foreground">{t('capture.consent.help')}</p>
                {CHANNELS.map((channel) => {
                    const enabled = possible.includes(channel);
                    const text = options?.consent_texts.find((c) => c.channel === channel);
                    return (
                        <label key={channel} className="igr:flex igr:items-start igr:gap-3 igr:rounded-md igr:p-1">
                            <input
                                type="checkbox"
                                className="igr:mt-0.5 igr:size-4 igr:accent-primary"
                                checked={channels.includes(channel)}
                                disabled={!enabled}
                                onChange={(e) =>
                                    onChange(fields, e.target.checked ? CHANNELS.filter((c) => c === channel || channels.includes(c)) : channels.filter((c) => c !== channel))
                                }
                            />
                            <span className="igr:min-w-0">
                                <span className="igr:text-[13px] igr:font-medium">{t('capture.consent.channel', { channel: t(`capture.channels.${channel}`) })}</span>
                                <span className="igr:block igr:text-caption igr:text-muted-foreground">
                                    {enabled
                                        ? text
                                            ? `«${text.label}»`
                                            : t('capture.consent.noText')
                                        : t('capture.consent.needsField', { field: t(`capture.fields.builtin.${consentField(channel)}`) })}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </fieldset>
        </div>
    );
}

function FieldRow({
    field,
    index,
    total,
    onChange,
    onMove,
    onRemove,
}: {
    field: LandingField;
    index: number;
    total: number;
    onChange: (patch: Partial<LandingField>) => void;
    onMove: (step: -1 | 1) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const id = useId();
    const type = fieldType(field);
    const kind = isCustom(field.key) ? t(`capture.fields.types.${type}`) : t(`capture.fields.builtin.${field.key}`);
    return (
        <li className="igr:rounded-lg igr:border igr:bg-card igr:p-3">
            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-x-3 igr:gap-y-2">
                <div className="igr:min-w-0 igr:flex-1 igr:basis-48 igr:space-y-1">
                    <Label htmlFor={`${id}-label`} className="igr:flex igr:items-center igr:gap-2">
                        {t('capture.fields.label')}
                        <Badge variant="outline">{kind}</Badge>
                    </Label>
                    <Input
                        id={`${id}-label`}
                        value={field.label}
                        maxLength={80}
                        onChange={(e) => onChange({ label: e.target.value })}
                        aria-invalid={field.label.trim() === '' || undefined}
                    />
                </div>
                <label className="igr:flex igr:h-9 igr:items-center igr:gap-2 igr:text-[13px]">
                    <input type="checkbox" className="igr:size-4 igr:accent-primary" checked={field.required ?? false} onChange={(e) => onChange({ required: e.target.checked })} />
                    {t('capture.fields.required')}
                </label>
                <div className="igr:flex igr:items-center">
                    <Button variant="ghost" size="icon" disabled={index === 0} onClick={() => onMove(-1)} aria-label={t('capture.fields.up', { name: field.label })}>
                        <ArrowUp aria-hidden />
                    </Button>
                    <Button variant="ghost" size="icon" disabled={index === total - 1} onClick={() => onMove(1)} aria-label={t('capture.fields.down', { name: field.label })}>
                        <ArrowDown aria-hidden />
                    </Button>
                    <Button variant="ghost" size="icon" onClick={onRemove} aria-label={t('capture.fields.remove', { name: field.label })}>
                        <Trash2 aria-hidden />
                    </Button>
                </div>
            </div>
            {type === 'select' ? (
                <div className="igr:mt-3 igr:space-y-1">
                    <Label htmlFor={`${id}-options`}>{t('capture.fields.options')}</Label>
                    <Textarea
                        id={`${id}-options`}
                        rows={3}
                        value={(field.options ?? []).join('\n')}
                        placeholder={t('capture.fields.optionsPlaceholder')}
                        onChange={(e) => onChange({ options: e.target.value.split('\n').slice(0, 20) })}
                        aria-invalid={(field.options ?? []).every((o) => o.trim() === '') || undefined}
                    />
                    <p className="igr:text-caption igr:text-muted-foreground">{field.key === 'service' ? t('capture.fields.serviceHelp') : t('capture.fields.optionsHelp')}</p>
                </div>
            ) : type !== 'checkbox' ? (
                <div className="igr:mt-3 igr:space-y-1">
                    <Label htmlFor={`${id}-ph`}>{t('capture.fields.placeholder')}</Label>
                    <Input id={`${id}-ph`} value={field.placeholder ?? ''} maxLength={80} onChange={(e) => onChange({ placeholder: e.target.value })} />
                </div>
            ) : null}
        </li>
    );
}
