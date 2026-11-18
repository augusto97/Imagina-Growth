import { ArrowLeft, Copy, Pause, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useCalculator, useCalculators, useQuoteMutations } from './api';
import { formatRange, newField, sampleAnswers } from './model';
import { statusVariant } from './QuotesPage';
import type { Calculator, CalculatorStatus, Estimate, FieldType, QuoteCaptureSettings, QuoteDefinition, QuoteField, QuoteOption } from './types';

/** Constructor de una calculadora con vista previa del precio calculado en el hub. */
export function QuoteEditorPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { calculatorId = '' } = useParams();
    const calc = useCalculator(workspace.id, calculatorId);
    const list = useCalculators(workspace.id);
    const back = `/w/${workspace.id}/en-el-sitio/cotizador`;

    if (calc.isError) {
        return (
            <>
                <PageHeader title={t('quotes.title')} />
                <ErrorState error={calc.error} onRetry={() => void calc.refetch()} />
            </>
        );
    }
    if (calc.isPending || list.isPending) {
        return (
            <>
                <PageHeader title={t('quotes.title')} />
                <Skeleton className="igr:h-96" />
            </>
        );
    }
    return <Editor key={calc.data.id + (calc.data.updated_at ?? '')} calc={calc.data} canManage={list.data?.can_manage ?? false} sites={list.data?.sites ?? []} currency={list.data?.currency ?? workspace.currency} back={back} />;
}

function Editor({ calc, canManage, sites, currency, back }: { calc: Calculator; canManage: boolean; sites: { id: string; name: string }[]; currency: string; back: string }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { save, remove, preview } = useQuoteMutations(workspace.id);
    const [name, setName] = useState(calc.name);
    const [siteId, setSiteId] = useState(calc.site_id ?? '');
    const [def, setDef] = useState<QuoteDefinition>(calc.definition);
    const [capture, setCapture] = useState<QuoteCaptureSettings>(calc.capture);
    const [answers, setAnswers] = useState<Record<string, unknown>>(() => sampleAnswers(calc.definition));
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const cur = def.currency ?? currency;
    const disabled = !canManage;
    const setD = <K extends keyof QuoteDefinition>(k: K, v: QuoteDefinition[K]) => setDef((d) => ({ ...d, [k]: v }));
    const setC = <K extends keyof QuoteCaptureSettings>(k: K, v: QuoteCaptureSettings[K]) => setCapture((c) => ({ ...c, [k]: v }));
    const setField = (i: number, patch: Partial<QuoteField>) => setD('fields', def.fields.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    const setOption = (i: number, o: number, patch: Partial<QuoteOption>) => setField(i, { options: (def.fields[i]?.options ?? []).map((x, j) => (j === o ? { ...x, ...patch } : x)) });

    // Vista previa: el hub calcula con la definición sin guardar (mismo motor que en el sitio).
    const { mutate: runPreview } = preview;
    useEffect(() => {
        const handle = window.setTimeout(() => {
            runPreview(
                { id: calc.id, answers: sampleAnswers(def, answers), definition: def, kind: calc.kind },
                {
                    onSuccess: (e) => { setEstimate(e); setPreviewError(null); },
                    onError: (e) => { setEstimate(null); setPreviewError(isApiError(e) ? (Object.values(e.fieldErrors)[0] ?? e.message) : t('errors.generic')); },
                },
            );
        }, 400);
        return () => window.clearTimeout(handle);
    }, [def, answers, calc.id, calc.kind, runPreview, t]);

    const persist = (status?: CalculatorStatus) =>
        save.mutate(
            { id: calc.id, data: { name: name.trim(), site_id: siteId === '' ? null : siteId, definition: def, capture, ...(status ? { status } : {}) } },
            { onSuccess: () => toast.success(t(status === 'active' ? 'quotes.editor.published' : status === 'paused' ? 'quotes.editor.paused' : 'quotes.editor.saved')), onError: (e) => toast.error(errorMessage(e, t)) },
        );
    const copy = (text: string) => void navigator.clipboard.writeText(text).then(() => toast.success(t('common.copied')));

    return (
        <>
            <PageHeader
                title={
                    <span className="igr:flex igr:items-center igr:gap-2">
                        <Link to={back} aria-label={t('common.back')} className="igr:text-muted-foreground igr:hover:text-foreground">
                            <ArrowLeft className="igr:size-4" />
                        </Link>
                        {calc.name}
                        <Badge variant={statusVariant(calc.status)}>{t(`quotes.status.${calc.status}`)}</Badge>
                    </span>
                }
                description={t(`quotes.kindHelp.${calc.kind}`)}
                actions={
                    canManage ? (
                        <div className="igr:flex igr:gap-2">
                            <Button variant="outline" onClick={() => persist()} loading={save.isPending && save.variables.data.status === undefined}>
                                {t('common.save')}
                            </Button>
                            {calc.status === 'active' ? (
                                <Button variant="outline" onClick={() => persist('paused')}>
                                    <Pause />
                                    {t('quotes.editor.pause')}
                                </Button>
                            ) : (
                                <Button onClick={() => persist('active')}>
                                    <Play />
                                    {t('quotes.editor.publish')}
                                </Button>
                            )}
                        </div>
                    ) : null
                }
            />
            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <div className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.editor.general')}</CardTitle>
                        </CardHeader>
                        <CardContent className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            <div>
                                <Label htmlFor="qc-name">{t('quotes.editor.name')}</Label>
                                <Input id="qc-name" className="igr:mt-1" value={name} maxLength={160} disabled={disabled} onChange={(e) => setName(e.target.value)} />
                            </div>
                            <div>
                                <Label htmlFor="qc-site">{t('quotes.editor.site')}</Label>
                                <Select id="qc-site" className="igr:mt-1" value={siteId} disabled={disabled} onChange={(e) => setSiteId(e.target.value)}>
                                    <option value="">{t('quotes.editor.allSites')}</option>
                                    {sites.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <div className="igr:sm:col-span-2">
                                <Label>{t('quotes.editor.embed')}</Label>
                                <div className="igr:mt-1 igr:flex igr:gap-2">
                                    <code className="igr:flex-1 igr:truncate igr:rounded igr:bg-muted igr:px-2 igr:py-1.5 igr:text-[13px]">{calc.shortcode}</code>
                                    <Button variant="outline" size="icon" aria-label={t('common.copy')} onClick={() => copy(calc.shortcode)}>
                                        <Copy />
                                    </Button>
                                </div>
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('quotes.editor.embedHelp')}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {calc.kind === 'package' || def.packages.length > 0 ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('quotes.editor.packages')}</CardTitle>
                                <CardDescription>{t('quotes.editor.packagesHelp')}</CardDescription>
                            </CardHeader>
                            <CardContent className="igr:space-y-2">
                                {def.packages.map((p, i) => (
                                    <div key={i} className="igr:grid igr:items-end igr:gap-2 igr:sm:grid-cols-[1fr_1.4fr_9rem_auto]">
                                        <Input aria-label={t('quotes.editor.packageName')} value={p.label} disabled={disabled} placeholder={t('quotes.editor.packageName')} onChange={(e) => setD('packages', def.packages.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
                                        <Input aria-label={t('quotes.editor.packageDescription')} value={p.description ?? ''} disabled={disabled} placeholder={t('quotes.editor.packageDescription')} onChange={(e) => setD('packages', def.packages.map((x, j) => (j === i ? { ...x, description: e.target.value } : x)))} />
                                        <Input aria-label={t('quotes.editor.price')} type="number" min={0} value={p.price} disabled={disabled} onChange={(e) => setD('packages', def.packages.map((x, j) => (j === i ? { ...x, price: Number(e.target.value) } : x)))} />
                                        <Button variant="ghost" size="icon" aria-label={t('quotes.editor.remove')} disabled={disabled} onClick={() => setD('packages', def.packages.filter((_, j) => j !== i))}>
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                                {canManage ? (
                                    <Button size="sm" variant="outline" onClick={() => setD('packages', [...def.packages, { key: `paquete_${def.packages.length + 1}`, label: '', description: null, price: 0 }])}>
                                        <Plus />
                                        {t('quotes.editor.addPackage')}
                                    </Button>
                                ) : null}
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.editor.questions')}</CardTitle>
                            <CardDescription>{t(`quotes.editor.questionsHelp.${calc.kind}`)}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:space-y-3">
                            {def.fields.map((f, i) => (
                                <div key={i} className="igr:space-y-2 igr:rounded-md igr:border igr:p-3">
                                    <div className="igr:grid igr:items-end igr:gap-2 igr:sm:grid-cols-[1fr_10rem_auto_auto]">
                                        <div>
                                            <Label htmlFor={`f-${i}`}>{t('quotes.editor.question')}</Label>
                                            <Input id={`f-${i}`} className="igr:mt-1" value={f.label} disabled={disabled} onChange={(e) => setField(i, { label: e.target.value })} />
                                        </div>
                                        <div>
                                            <Label htmlFor={`ft-${i}`}>{t('quotes.editor.type')}</Label>
                                            <Select id={`ft-${i}`} className="igr:mt-1" value={f.type} disabled={disabled} onChange={(e) => setD('fields', def.fields.map((x, j) => (j === i ? { ...newField(e.target.value as FieldType, j, t), key: x.key, label: x.label } : x)))}>
                                                {(['number', 'select', 'checkbox'] as const).map((type) => (
                                                    <option key={type} value={type}>
                                                        {t(`quotes.fieldType.${type}`)}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                        <label className="igr:flex igr:h-8 igr:items-center igr:gap-1.5 igr:text-[13px]">
                                            <input type="checkbox" checked={f.required} disabled={disabled} onChange={(e) => setField(i, { required: e.target.checked })} />
                                            {t('quotes.editor.required')}
                                        </label>
                                        <Button variant="ghost" size="icon" aria-label={t('quotes.editor.remove')} disabled={disabled} onClick={() => setD('fields', def.fields.filter((_, j) => j !== i))}>
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    {f.type === 'number' ? (
                                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-4">
                                            <NumberBox label={t('quotes.editor.unitPrice', { currency: cur })} value={f.unit_price ?? 0} disabled={disabled} onChange={(v) => setField(i, { unit_price: v ?? 0 })} />
                                            <div>
                                                <Label className="igr:text-xs">{t('quotes.editor.unit')}</Label>
                                                <Input className="igr:mt-1" value={f.unit ?? ''} maxLength={20} disabled={disabled} placeholder="m²" onChange={(e) => setField(i, { unit: e.target.value || null })} />
                                            </div>
                                            <NumberBox label={t('quotes.editor.min')} value={f.min ?? 0} disabled={disabled} onChange={(v) => setField(i, { min: v ?? 0 })} />
                                            <NumberBox label={t('quotes.editor.max')} value={f.max ?? null} disabled={disabled} onChange={(v) => setField(i, { max: v })} />
                                        </div>
                                    ) : (
                                        <div className="igr:space-y-1.5">
                                            <div className="igr:grid igr:gap-2 igr:text-xs igr:text-muted-foreground igr:sm:grid-cols-[1fr_8rem_7rem_auto]">
                                                <span>{t('quotes.editor.option')}</span>
                                                <span>{t('quotes.editor.adds', { currency: cur })}</span>
                                                <span>{t('quotes.editor.multiplier')}</span>
                                                <span />
                                            </div>
                                            {(f.options ?? []).map((o, j) => (
                                                <div key={j} className="igr:grid igr:items-center igr:gap-2 igr:sm:grid-cols-[1fr_8rem_7rem_auto]">
                                                    <Input aria-label={t('quotes.editor.option')} value={o.label} disabled={disabled} onChange={(e) => setOption(i, j, { label: e.target.value })} />
                                                    <Input aria-label={t('quotes.editor.adds', { currency: cur })} type="number" value={o.price} disabled={disabled} onChange={(e) => setOption(i, j, { price: Number(e.target.value) })} />
                                                    <Input aria-label={t('quotes.editor.multiplier')} type="number" step="0.05" min="0.01" value={o.multiplier} disabled={disabled} onChange={(e) => setOption(i, j, { multiplier: Number(e.target.value) || 1 })} />
                                                    <Button variant="ghost" size="icon" aria-label={t('quotes.editor.remove')} disabled={disabled || (f.options ?? []).length <= 1} onClick={() => setField(i, { options: (f.options ?? []).filter((_, k) => k !== j) })}>
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            ))}
                                            {canManage ? (
                                                <Button size="sm" variant="ghost" onClick={() => setField(i, { options: [...(f.options ?? []), { value: `op_${(f.options ?? []).length + 1}`, label: t('quotes.builder.optionN', { n: (f.options ?? []).length + 1 }), price: 0, multiplier: 1 }] })}>
                                                    <Plus />
                                                    {t('quotes.editor.addOption')}
                                                </Button>
                                            ) : null}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {canManage ? (
                                <div className="igr:flex igr:flex-wrap igr:gap-2">
                                    {(['number', 'select', 'checkbox'] as const).map((type) => (
                                        <Button key={type} size="sm" variant="outline" onClick={() => setD('fields', [...def.fields, newField(type, def.fields.length, t)])}>
                                            <Plus />
                                            {t(`quotes.fieldType.${type}`)}
                                        </Button>
                                    ))}
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.editor.pricing')}</CardTitle>
                            <CardDescription>{t('quotes.editor.pricingHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:grid igr:gap-3 igr:sm:grid-cols-4">
                            <NumberBox label={t('quotes.editor.base', { currency: cur })} value={def.base_price} disabled={disabled} onChange={(v) => setD('base_price', v ?? 0)} />
                            <NumberBox label={t('quotes.editor.minimum', { currency: cur })} value={def.minimum} disabled={disabled} onChange={(v) => setD('minimum', v ?? 0)} />
                            <NumberBox label={t('quotes.editor.range')} value={def.range_pct} disabled={disabled} onChange={(v) => setD('range_pct', v ?? 0)} />
                            <NumberBox label={t('quotes.editor.round')} value={def.round_to} disabled={disabled} onChange={(v) => setD('round_to', v ?? 0)} />
                            <div className="igr:sm:col-span-2">
                                <Label htmlFor="qc-note">{t('quotes.editor.note')}</Label>
                                <Textarea id="qc-note" className="igr:mt-1" rows={2} maxLength={400} value={def.note ?? ''} disabled={disabled} onChange={(e) => setD('note', e.target.value || null)} />
                            </div>
                            <div className="igr:sm:col-span-2">
                                <Label htmlFor="qc-internal">{t('quotes.editor.internal')}</Label>
                                <Textarea id="qc-internal" className="igr:mt-1" rows={2} maxLength={2000} value={def.internal_notes ?? ''} disabled={disabled} onChange={(e) => setD('internal_notes', e.target.value || null)} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.editor.capture')}</CardTitle>
                            <CardDescription>{t('quotes.editor.captureHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:space-y-3 igr:text-[13px]">
                            <label className="igr:flex igr:items-center igr:gap-2">
                                <input type="checkbox" checked={capture.required} disabled={disabled} onChange={(e) => setC('required', e.target.checked)} />
                                {t('quotes.editor.captureRequired')}
                            </label>
                            <div className="igr:flex igr:flex-wrap igr:gap-4">
                                {(['first_name', 'last_name', 'phone', 'email'] as const).map((f) => (
                                    <label key={f} className="igr:flex igr:items-center igr:gap-1.5">
                                        <input type="checkbox" checked={capture.fields.includes(f)} disabled={disabled} onChange={(e) => setC('fields', e.target.checked ? [...capture.fields, f] : capture.fields.filter((x) => x !== f))} />
                                        {t(`quotes.contactField.${f}`)}
                                    </label>
                                ))}
                            </div>
                            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="qc-require">{t('quotes.editor.require')}</Label>
                                    <Select id="qc-require" className="igr:mt-1" value={capture.require} disabled={disabled} onChange={(e) => setC('require', e.target.value as QuoteCaptureSettings['require'])}>
                                        {(['phone_or_email', 'phone', 'email'] as const).map((r) => (
                                            <option key={r} value={r}>
                                                {t(`quotes.require.${r}`)}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                                <div>
                                    <Label htmlFor="qc-wa">{t('quotes.editor.whatsapp')}</Label>
                                    <Input id="qc-wa" className="igr:mt-1" inputMode="numeric" placeholder="573001234567" value={capture.whatsapp_number ?? ''} disabled={disabled} onChange={(e) => setC('whatsapp_number', e.target.value.replace(/\D/g, '') || null)} />
                                </div>
                            </div>
                            <fieldset>
                                <legend className="igr:font-medium">{t('quotes.editor.consents')}</legend>
                                <div className="igr:mt-1 igr:flex igr:gap-4">
                                    {(['whatsapp', 'email', 'sms'] as const).map((c) => (
                                        <label key={c} className="igr:flex igr:items-center igr:gap-1.5">
                                            <input type="checkbox" checked={capture.consent_channels.includes(c)} disabled={disabled} onChange={(e) => setC('consent_channels', e.target.checked ? [...capture.consent_channels, c] : capture.consent_channels.filter((x) => x !== c))} />
                                            {t(`quotes.channel.${c}`)}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                                {(['title', 'cta', 'success'] as const).map((k) => (
                                    <div key={k}>
                                        <Label htmlFor={`qc-${k}`}>{t(`quotes.editor.texts.${k}`)}</Label>
                                        <Input id={`qc-${k}`} className="igr:mt-1" maxLength={200} value={capture[k] ?? ''} placeholder={t(`quotes.editor.texts.${k}Placeholder`)} disabled={disabled} onChange={(e) => setC(k, e.target.value || null)} />
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                    {canManage ? (
                        <div className="igr:flex igr:justify-between">
                            <Button
                                variant="ghost"
                                className="igr:text-destructive"
                                onClick={() => {
                                    if (window.confirm(t('quotes.editor.deleteConfirm'))) remove.mutate(calc.id, { onSuccess: () => void navigate(back) });
                                }}
                            >
                                <Trash2 />
                                {t('quotes.editor.delete')}
                            </Button>
                            <Button onClick={() => persist()} loading={save.isPending}>
                                {t('common.save')}
                            </Button>
                        </div>
                    ) : null}
                </div>

                <div className="igr:xl:sticky igr:xl:top-4 igr:self-start">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.editor.preview')}</CardTitle>
                            <CardDescription>{t('quotes.editor.previewHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:space-y-3 igr:text-[13px]">
                            {def.packages.length > 0 ? (
                                <fieldset className="igr:space-y-1">
                                    <legend className="igr:mb-1 igr:font-medium">{t('quotes.editor.choosePackage')}</legend>
                                    {def.packages.map((p) => (
                                        <label key={p.key} className="igr:flex igr:items-center igr:gap-2">
                                            <input type="radio" name="pv-package" checked={answers.package === p.key} onChange={() => setAnswers({ ...answers, package: p.key })} />
                                            {p.label || '—'}
                                        </label>
                                    ))}
                                </fieldset>
                            ) : null}
                            {def.fields.map((f) => (
                                <div key={f.key}>
                                    <p className="igr:mb-1 igr:font-medium">{f.label || '—'}</p>
                                    {f.type === 'number' ? (
                                        <div className="igr:flex igr:items-center igr:gap-2">
                                            <Input type="number" className="igr:w-32" aria-label={f.label} value={typeof answers[f.key] === 'number' ? (answers[f.key] as number) : ''} onChange={(e) => setAnswers({ ...answers, [f.key]: e.target.value === '' ? null : Number(e.target.value) })} />
                                            <span className="igr:text-muted-foreground">{f.unit}</span>
                                        </div>
                                    ) : f.type === 'select' ? (
                                        <Select aria-label={f.label} value={typeof answers[f.key] === 'string' ? (answers[f.key] as string) : ''} onChange={(e) => setAnswers({ ...answers, [f.key]: e.target.value })}>
                                            {(f.options ?? []).map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <div className="igr:flex igr:flex-wrap igr:gap-3">
                                            {(f.options ?? []).map((o) => {
                                                const picked = Array.isArray(answers[f.key]) ? (answers[f.key] as string[]) : [];
                                                return (
                                                    <label key={o.value} className="igr:flex igr:items-center igr:gap-1.5">
                                                        <input type="checkbox" checked={picked.includes(o.value)} onChange={(e) => setAnswers({ ...answers, [f.key]: e.target.checked ? [...picked, o.value] : picked.filter((v) => v !== o.value) })} />
                                                        {o.label}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            ))}
                            <div className="igr:rounded-md igr:bg-muted igr:p-3" aria-live="polite">
                                {estimate ? (
                                    <>
                                        <p className="igr:text-xs igr:text-muted-foreground">{t('quotes.editor.estimate')}</p>
                                        <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{formatRange(estimate.min, estimate.max, cur, i18n.language)}</p>
                                        {estimate.lines.length > 0 ? (
                                            <ul className="igr:mt-1 igr:space-y-0.5 igr:text-xs igr:text-muted-foreground">
                                                {estimate.lines.map((l, i) => (
                                                    <li key={i} className="igr:flex igr:justify-between igr:gap-2">
                                                        <span>{l.label}</span>
                                                        <span className="igr:tabular-nums">{formatRange(l.amount, l.amount, cur, i18n.language)}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </>
                                ) : (
                                    <p className="igr:text-muted-foreground">{previewError ?? t('common.loading')}</p>
                                )}
                            </div>
                            {capture.required ? <p className="igr:text-xs igr:text-muted-foreground">{t('quotes.editor.previewCapture')}</p> : null}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function NumberBox({ label, value, onChange, disabled }: { label: string; value: number | null; onChange: (v: number | null) => void; disabled: boolean }) {
    return (
        <div>
            <Label className="igr:text-xs">{label}</Label>
            <Input className="igr:mt-1" type="number" value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />
        </div>
    );
}
