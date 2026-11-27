import { Languages } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useCan } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { SuggestVariantsButton } from '@/features/copilot/Suggest';
import { insertAt } from '../content';
import { estimateSms, maxParts, measureSms, smsOverflow, transliterateSms, type SmsLimits } from '../sms';
import { VariablePicker } from './VariablePicker';

/** Cómo se muestran las letras que pasan el SMS a UCS-2: «á», «ó». */
export function quoteChars(chars: string[]): string {
    return chars.map((c) => `«${c === '\n' ? '↵' : c}»`).join(', ');
}

/**
 * El texto de un SMS (modulos.md M08): el contador del peor caso (variables a su largo máximo, como el hub), qué
 * letra lo pasa a 70 caracteres con el botón para cambiarlas por letras sin tilde, y el permiso de 2 partes, que solo
 * da el dueño. Sin ese permiso el hub no guarda ni envía un SMS que se partiría.
 */
export function SmsEditor({
    text,
    allowMultipart,
    limits,
    canEdit,
    error,
    allowError,
    onChange,
}: {
    text: string;
    allowMultipart: boolean;
    limits: SmsLimits | null;
    canEdit: boolean;
    error?: string | undefined;
    allowError?: string | undefined;
    onChange: (patch: { body_text?: string; sms_allow_multipart?: boolean }) => void;
}) {
    const { t } = useTranslation();
    const isOwner = useCan('manageBilling');
    const fieldId = useId();
    const allowId = useId();
    const info = estimateSms(text, limits);
    const overflow = smsOverflow(info, allowMultipart);
    const limit = maxParts(allowMultipart) === 1 ? info.singleLimit : maxParts(allowMultipart) * info.partLimit;
    const plain = transliterateSms(text);
    const canTransliterate = plain !== text;
    const leftover = canTransliterate ? measureSms(plain).offenders : info.offenders;
    const ratio = limit > 0 ? Math.min(1, info.length / limit) : 0;
    const tone = overflow ? 'destructive' : info.encoding === 'UCS-2' ? 'warning' : 'success';

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('templates.sms.title')}</CardTitle>
                <CardDescription>{t('templates.sms.help')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                    <Label htmlFor={fieldId}>{t('templates.sms.text')}</Label>
                    <VariablePicker
                        disabled={!canEdit}
                        onPick={(expr) => {
                            const el = document.getElementById(fieldId) as HTMLTextAreaElement | null;
                            const r = insertAt(text, el?.selectionStart ?? text.length, el?.selectionEnd ?? text.length, expr);
                            onChange({ body_text: r.value });
                            requestAnimationFrame(() => {
                                el?.focus();
                                el?.setSelectionRange(r.caret, r.caret);
                            });
                        }}
                    />
                </div>
                <Textarea id={fieldId} rows={5} value={text} onChange={(e) => onChange({ body_text: e.target.value })} disabled={!canEdit} aria-invalid={error ? true : undefined} />

                <div className="igr:space-y-1.5">
                    <div className="igr:h-1.5 igr:overflow-hidden igr:rounded-full igr:bg-muted" aria-hidden="true">
                        <div
                            className={cn('igr:h-full igr:rounded-full igr:transition-all', tone === 'destructive' ? 'igr:bg-destructive' : tone === 'warning' ? 'igr:bg-warning' : 'igr:bg-success')}
                            style={{ width: `${String(Math.round(ratio * 100))}%` }}
                        />
                    </div>
                    <p className={cn('igr:text-xs igr:tabular-nums', overflow ? 'igr:text-destructive' : 'igr:text-muted-foreground')} aria-live="polite" data-testid="sms-counter">
                        {t(info.hasVariables && limits ? 'templates.sms.counterWorst' : 'templates.sms.counter', { length: info.length, limit, count: Math.max(1, info.segments) })}
                    </p>
                    {info.hasVariables && limits ? (
                        <p className="igr:text-xs igr:text-muted-foreground">{t('templates.sms.variablesNote', { link: limits.link_length, store: limits.store_name_length })}</p>
                    ) : null}
                </div>

                {overflow ? (
                    <Callout tone="danger" title={t('templates.sms.tooLongTitle')}>
                        {t('templates.sms.tooLong', { over: overflow.over, limit: overflow.limit })}
                    </Callout>
                ) : null}

                {info.encoding === 'UCS-2' ? (
                    <Callout
                        tone="warning"
                        title={t('templates.sms.unicodeTitle', { chars: quoteChars(info.offenders) })}
                        action={
                            canEdit && canTransliterate ? (
                                <Button type="button" size="sm" variant="outline" onClick={() => onChange({ body_text: plain })}>
                                    <Languages />
                                    {t('templates.sms.transliterate')}
                                </Button>
                            ) : undefined
                        }
                    >
                        {t('templates.sms.unicodeBody')}
                        {canTransliterate && leftover.length > 0 ? ` ${t('templates.sms.unicodeLeft', { chars: quoteChars(leftover) })}` : ''}
                    </Callout>
                ) : null}

                {canEdit && text.trim() !== '' ? (
                    <div className="igr:flex igr:justify-end">
                        <SuggestVariantsButton text={text} kind="sms" onPick={(body_text) => onChange({ body_text })} />
                    </div>
                ) : null}

                <div className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:p-3">
                    <Checkbox
                        id={allowId}
                        checked={allowMultipart}
                        disabled={!canEdit || !isOwner}
                        onChange={(e) => onChange({ sms_allow_multipart: e.target.checked })}
                        className="igr:mt-0.5"
                    />
                    <div className="igr:space-y-0.5">
                        <Label htmlFor={allowId}>{t('templates.sms.allowMultipart')}</Label>
                        <p className="igr:text-xs igr:text-muted-foreground">{isOwner ? t('templates.sms.allowMultipartHelp') : t('templates.sms.allowMultipartOwner')}</p>
                        <FieldError message={allowError} />
                    </div>
                </div>
                <FieldError message={error} />
            </CardContent>
        </Card>
    );
}
