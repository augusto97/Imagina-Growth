import { ArrowLeft, ArrowRight, Check, FileSpreadsheet, ShieldAlert, ShieldCheck, Upload } from 'lucide-react';
import type { TFunction } from 'i18next';
import { useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';
import { useContactAbilities } from '../abilities';
import { type ConsentChannel, type ImportField, type ImportPreview, useImports, usePreviewImport, useStartImport } from '../api';
import { errorMessage } from '../errors';
import { CONSENT_CHANNELS } from '../labels';
import { type ConsentDraft, STANDARD_FIELDS, consentPayload, consentProblems, customKey, initialMapping, isCustom, mappingProblems } from './mapping';
import { ImportStatusBadge } from './StatusBadge';

const STEPS = ['upload', 'map', 'consent', 'confirm'] as const;
type Step = (typeof STEPS)[number];

/** Importación CSV de contactos con mapeo de columnas y declaración de consentimiento obligatoria (M03). */
export function ImportPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const navigate = useNavigate();
    const preview = usePreviewImport(workspace.id);
    const start = useStartImport(workspace.id);
    const [step, setStep] = useState<Step>('upload');
    const [file, setFile] = useState<File | null>(null);
    const [data, setData] = useState<ImportPreview | null>(null);
    const [mapping, setMapping] = useState<Record<string, ImportField>>({});
    const [consent, setConsent] = useState<ConsentDraft>({ mode: null, channels: [], source: '', date: '' });
    const [showErrors, setShowErrors] = useState(false);
    const [today] = useState(() => new Intl.DateTimeFormat('en-CA', { timeZone: workspace.timezone }).format(new Date()));

    if (!can.edit) {
        return <EmptyState icon={ShieldAlert} title={t('contacts.import.forbidden')} description={t('contacts.import.forbiddenHelp')} />;
    }

    const choose = (picked: File) => {
        setFile(picked);
        preview.mutate(picked, {
            onSuccess: (result) => {
                setData(result);
                setMapping(initialMapping(result.headers, result.suggested));
                setStep('map');
            },
        });
    };

    const problems = mappingProblems(mapping);
    const consentIssues = consentProblems(consent, today);
    const next = () => {
        if ((step === 'map' && problems.length > 0) || (step === 'consent' && consentIssues.length > 0)) {
            setShowErrors(true);
            return;
        }
        setShowErrors(false);
        setStep(STEPS[STEPS.indexOf(step) + 1] ?? step);
    };
    const prev = () => {
        setShowErrors(false);
        setStep(STEPS[STEPS.indexOf(step) - 1] ?? step);
    };
    const submit = () => {
        if (!file) {
            return;
        }
        start.mutate(
            { file, mapping, consent: consentPayload(consent) },
            { onSuccess: (created) => void navigate(`/w/${workspace.id}/contactos/importar/${created.id}`) },
        );
    };

    return (
        <>
            <Link
                to={`/w/${workspace.id}/contactos`}
                className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground"
            >
                <ArrowLeft className="igr:size-3.5" />
                {t('contacts.title')}
            </Link>
            <PageHeader title={t('contacts.import.title')} description={t('contacts.import.description')} />

            <ol className="igr:mb-4 igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px]" aria-label={t('contacts.import.steps')}>
                {STEPS.map((s, i) => {
                    const done = STEPS.indexOf(step) > i;
                    const current = s === step;
                    return (
                        <li key={s} className="igr:flex igr:items-center igr:gap-2" aria-current={current ? 'step' : undefined}>
                            {i > 0 && <span className="igr:h-px igr:w-6 igr:bg-border" aria-hidden="true" />}
                            <span
                                className={cn(
                                    'igr:flex igr:size-5 igr:items-center igr:justify-center igr:rounded-full igr:border igr:text-[11px] igr:font-medium',
                                    current && 'igr:border-primary igr:bg-primary igr:text-primary-foreground',
                                    done && 'igr:border-primary/40 igr:bg-primary/10 igr:text-primary',
                                )}
                            >
                                {done ? <Check className="igr:size-3" /> : i + 1}
                            </span>
                            <span className={current ? 'igr:font-medium' : 'igr:text-muted-foreground'}>{t(`contacts.import.step.${s}`)}</span>
                        </li>
                    );
                })}
            </ol>

            {step === 'upload' && (
                <>
                    <UploadStep onFile={choose} loading={preview.isPending} error={preview.isError ? errorMessage(preview.error, t) : null} />
                    <RecentImports />
                </>
            )}

            {step === 'map' && data && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('contacts.import.map.title')}</CardTitle>
                        <CardDescription>{t('contacts.import.map.description', { file: file?.name ?? '' })}</CardDescription>
                    </CardHeader>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('contacts.import.map.column')}</TH>
                                <TH>{t('contacts.import.map.sample')}</TH>
                                <TH className="igr:w-72">{t('contacts.import.map.field')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {data.headers.map((header, col) => (
                                <TR key={header}>
                                    <TD className="igr:font-medium">{header}</TD>
                                    <TD className="igr:max-w-64 igr:truncate igr:text-xs igr:text-muted-foreground">
                                        {data.rows
                                            .map((r) => r[col] ?? '')
                                            .filter((v) => v !== '')
                                            .slice(0, 3)
                                            .join(' · ') || '—'}
                                    </TD>
                                    <TD>
                                        <FieldPicker header={header} value={mapping[header] ?? 'ignore'} onChange={(field) => setMapping({ ...mapping, [header]: field })} />
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <CardContent className="igr:pt-3">
                        {showErrors &&
                            problems.map((p, i) => (
                                <FieldError
                                    key={i}
                                    message={
                                        p.code === 'needsIdentifier'
                                            ? t('contacts.import.map.needsIdentifier')
                                            : p.code === 'duplicate'
                                              ? t('contacts.import.map.duplicate', { field: fieldLabel(p.field, t), headers: p.headers.join(', ') })
                                              : t('contacts.import.map.invalidCustom', { header: p.header })
                                    }
                                />
                            ))}
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('contacts.import.map.phoneHelp', { country: t(`countries.${workspace.country}`, { defaultValue: workspace.country }) })}</p>
                    </CardContent>
                    <WizardFooter onBack={prev} onNext={next} />
                </Card>
            )}

            {step === 'consent' && <ConsentStep draft={consent} onChange={setConsent} today={today} problems={showErrors ? consentIssues : []} onBack={prev} onNext={next} />}

            {step === 'confirm' && data && file && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('contacts.import.confirm.title')}</CardTitle>
                        <CardDescription>{t('contacts.import.confirm.description')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-4 igr:text-[13px]">
                        <Summary label={t('contacts.import.confirm.file')}>
                            <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                                <FileSpreadsheet className="igr:size-4 igr:text-muted-foreground" />
                                {file.name}
                                <span className="igr:text-muted-foreground">· {formatNumber(Math.ceil(file.size / 1024), workspace.locale)} KB</span>
                            </span>
                        </Summary>
                        <Summary label={t('contacts.import.confirm.columns')}>
                            <div className="igr:flex igr:flex-wrap igr:gap-1">
                                {Object.entries(mapping)
                                    .filter(([, f]) => f !== 'ignore')
                                    .map(([header, f]) => (
                                        <Badge key={header} variant="secondary">
                                            {header} → {fieldLabel(f, t)}
                                        </Badge>
                                    ))}
                            </div>
                            {Object.values(mapping).some((f) => f === 'ignore') && (
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                                    {t('contacts.import.confirm.ignored', { count: Object.values(mapping).filter((f) => f === 'ignore').length })}
                                </p>
                            )}
                        </Summary>
                        <Summary label={t('contacts.import.confirm.consent')}>
                            {consent.mode === 'declared' ? (
                                <span className="igr:inline-flex igr:flex-wrap igr:items-center igr:gap-1.5">
                                    <ShieldCheck className="igr:size-4 igr:text-success" />
                                    {t('contacts.import.confirm.consentDeclared', {
                                        channels: consent.channels.map((c) => t(`contacts.consentChannels.${c}`)).join(', '),
                                        date: formatDate(consent.date, workspace.locale, workspace.timezone),
                                    })}
                                    <span className="igr:w-full igr:text-xs igr:text-muted-foreground">«{consent.source.trim()}»</span>
                                </span>
                            ) : (
                                <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                                    <ShieldAlert className="igr:size-4 igr:text-warning" />
                                    {t('contacts.import.confirm.consentNone')}
                                </span>
                            )}
                        </Summary>
                        {start.isError && <ErrorState error={start.error} />}
                    </CardContent>
                    <CardFooter className="igr:justify-between">
                        <Button variant="ghost" onClick={prev}>
                            <ArrowLeft />
                            {t('common.back')}
                        </Button>
                        <Button loading={start.isPending} onClick={submit}>
                            <Upload />
                            {t('contacts.import.confirm.start')}
                        </Button>
                    </CardFooter>
                </Card>
            )}
        </>
    );
}

function fieldLabel(field: string, t: TFunction): string {
    return isCustom(field) ? t('contacts.import.fields.customNamed', { key: field.slice('custom:'.length) }) : t(`contacts.import.fields.${field}`);
}

function Summary({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="igr:grid igr:gap-1 igr:sm:grid-cols-[160px_1fr]">
            <p className="igr:text-muted-foreground">{label}</p>
            <div>{children}</div>
        </div>
    );
}

function WizardFooter({ onBack, onNext }: { onBack: () => void; onNext: () => void }) {
    const { t } = useTranslation();
    return (
        <CardFooter className="igr:justify-between">
            <Button variant="ghost" onClick={onBack}>
                <ArrowLeft />
                {t('common.back')}
            </Button>
            <Button onClick={onNext}>
                {t('common.continue')}
                <ArrowRight />
            </Button>
        </CardFooter>
    );
}

function UploadStep({ onFile, loading, error }: { onFile: (file: File) => void; loading: boolean; error: string | null }) {
    const { t } = useTranslation();
    const input = useRef<HTMLInputElement | null>(null);
    const [over, setOver] = useState(false);

    return (
        <Card>
            <CardContent className="igr:p-4">
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        setOver(true);
                    }}
                    onDragLeave={() => setOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setOver(false);
                        const dropped = e.dataTransfer.files[0];
                        if (dropped) {
                            onFile(dropped);
                        }
                    }}
                    className={cn(
                        'igr:flex igr:flex-col igr:items-center igr:rounded-lg igr:border igr:border-dashed igr:px-6 igr:py-12 igr:text-center igr:transition-colors',
                        over && 'igr:border-primary igr:bg-primary/5',
                    )}
                >
                    <div className="igr:mb-3 igr:flex igr:size-10 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted">
                        <FileSpreadsheet className="igr:size-5 igr:text-muted-foreground" />
                    </div>
                    <p className="igr:text-sm igr:font-medium">{t('contacts.import.upload.title')}</p>
                    <p className="igr:mt-1 igr:max-w-md igr:text-[13px] igr:text-muted-foreground">{t('contacts.import.upload.help')}</p>
                    <input
                        ref={input}
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        className="igr:sr-only"
                        aria-label={t('contacts.import.upload.choose')}
                        onChange={(e) => {
                            const picked = e.target.files?.[0];
                            if (picked) {
                                onFile(picked);
                            }
                            e.target.value = '';
                        }}
                    />
                    <Button className="igr:mt-4" loading={loading} onClick={() => input.current?.click()}>
                        <Upload />
                        {t('contacts.import.upload.choose')}
                    </Button>
                    <FieldError message={error ?? undefined} />
                </div>
                <ul className="igr:mt-4 igr:grid igr:gap-2 igr:text-xs igr:text-muted-foreground igr:sm:grid-cols-3">
                    <li>{t('contacts.import.upload.tip1')}</li>
                    <li>{t('contacts.import.upload.tip2')}</li>
                    <li>{t('contacts.import.upload.tip3')}</li>
                </ul>
            </CardContent>
        </Card>
    );
}

function FieldPicker({ header, value, onChange }: { header: string; value: ImportField; onChange: (field: ImportField) => void }) {
    const { t } = useTranslation();
    const custom = isCustom(value);
    return (
        <div className="igr:flex igr:gap-2">
            <Select
                aria-label={t('contacts.import.map.fieldFor', { header })}
                value={custom ? '__custom' : value}
                className={value === 'ignore' ? 'igr:text-muted-foreground' : undefined}
                onChange={(e) => onChange(e.target.value === '__custom' ? `custom:${customKey(header) || 'campo'}` : (e.target.value as ImportField))}
            >
                <option value="ignore">{t('contacts.import.fields.ignore')}</option>
                {STANDARD_FIELDS.map((f) => (
                    <option key={f} value={f}>
                        {t(`contacts.import.fields.${f}`)}
                    </option>
                ))}
                <option value="__custom">{t('contacts.import.fields.custom')}</option>
            </Select>
            {custom && (
                <Input
                    aria-label={t('contacts.import.map.customKey', { header })}
                    className="igr:w-36 igr:font-mono igr:text-xs"
                    value={value.slice('custom:'.length)}
                    onChange={(e) => onChange(`custom:${e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').slice(0, 40)}`)}
                />
            )}
        </div>
    );
}

function ConsentStep({
    draft,
    onChange,
    today,
    problems,
    onBack,
    onNext,
}: {
    draft: ConsentDraft;
    onChange: (draft: ConsentDraft) => void;
    today: string;
    problems: string[];
    onBack: () => void;
    onNext: () => void;
}) {
    const { t } = useTranslation();
    const sourceId = useId();
    const dateId = useId();
    const toggle = (channel: ConsentChannel) =>
        onChange({ ...draft, channels: draft.channels.includes(channel) ? draft.channels.filter((c) => c !== channel) : [...draft.channels, channel] });

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('contacts.import.consent.title')}</CardTitle>
                <CardDescription>{t('contacts.import.consent.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                <div role="radiogroup" aria-label={t('contacts.import.consent.title')} className="igr:grid igr:gap-2 igr:md:grid-cols-2">
                    <ModeOption
                        checked={draft.mode === 'declared'}
                        onSelect={() => onChange({ ...draft, mode: 'declared' })}
                        icon={<ShieldCheck className="igr:size-4 igr:text-success" />}
                        title={t('contacts.import.consent.declared')}
                        help={t('contacts.import.consent.declaredHelp')}
                    />
                    <ModeOption
                        checked={draft.mode === 'none'}
                        onSelect={() => onChange({ ...draft, mode: 'none' })}
                        icon={<ShieldAlert className="igr:size-4 igr:text-warning" />}
                        title={t('contacts.import.consent.none')}
                        help={t('contacts.import.consent.noneHelp')}
                    />
                </div>
                {problems.includes('chooseMode') && <FieldError message={t('contacts.import.consent.chooseMode')} />}

                {draft.mode === 'declared' && (
                    <div className="igr:space-y-4 igr:rounded-lg igr:border igr:p-4">
                        <fieldset>
                            <legend className="igr:text-[13px] igr:font-medium">{t('contacts.import.consent.channels')}</legend>
                            <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-4">
                                {CONSENT_CHANNELS.map((c) => (
                                    <label key={c} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                        <input type="checkbox" className="igr:size-4 igr:accent-[var(--primary)]" checked={draft.channels.includes(c)} onChange={() => toggle(c)} />
                                        {t(`contacts.consentChannels.${c}`)}
                                    </label>
                                ))}
                            </div>
                            {problems.includes('channels') && <FieldError message={t('contacts.import.consent.channelsRequired')} />}
                        </fieldset>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor={sourceId}>{t('contacts.import.consent.source')}</Label>
                            <Textarea
                                id={sourceId}
                                maxLength={300}
                                placeholder={t('contacts.import.consent.sourcePlaceholder')}
                                value={draft.source}
                                aria-invalid={problems.includes('source') ? true : undefined}
                                onChange={(e) => onChange({ ...draft, source: e.target.value })}
                            />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('contacts.import.consent.sourceHelp')}</p>
                            {problems.includes('source') && <FieldError message={t('contacts.import.consent.sourceRequired')} />}
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor={dateId}>{t('contacts.import.consent.date')}</Label>
                            <Input
                                id={dateId}
                                type="date"
                                max={today}
                                className="igr:w-44"
                                value={draft.date}
                                aria-invalid={problems.includes('date') || problems.includes('futureDate') ? true : undefined}
                                onChange={(e) => onChange({ ...draft, date: e.target.value })}
                            />
                            {problems.includes('date') && <FieldError message={t('contacts.import.consent.dateRequired')} />}
                            {problems.includes('futureDate') && <FieldError message={t('contacts.import.consent.futureDate')} />}
                        </div>
                        <p className="igr:rounded-md igr:bg-muted/60 igr:p-2.5 igr:text-xs igr:text-muted-foreground">{t('contacts.import.consent.legal')}</p>
                    </div>
                )}
            </CardContent>
            <WizardFooter onBack={onBack} onNext={onNext} />
        </Card>
    );
}

function ModeOption({ checked, onSelect, icon, title, help }: { checked: boolean; onSelect: () => void; icon: ReactNode; title: string; help: string }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={checked}
            onClick={onSelect}
            className={cn(
                'igr:flex igr:gap-3 igr:rounded-lg igr:border igr:p-3 igr:text-left igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                checked ? 'igr:border-primary igr:bg-primary/5' : 'igr:hover:bg-accent',
            )}
        >
            <span className="igr:mt-0.5">{icon}</span>
            <span>
                <span className="igr:block igr:text-[13px] igr:font-medium">{title}</span>
                <span className="igr:mt-0.5 igr:block igr:text-xs igr:text-muted-foreground">{help}</span>
            </span>
        </button>
    );
}

function RecentImports() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const imports = useImports(workspace.id);
    if (!imports.data || imports.data.length === 0) {
        return null;
    }
    return (
        <Card className="igr:mt-4">
            <CardHeader>
                <CardTitle>{t('contacts.import.recent')}</CardTitle>
            </CardHeader>
            <Table>
                <THead>
                    <TR className="igr:hover:bg-transparent">
                        <TH>{t('contacts.import.status.file')}</TH>
                        <TH>{t('contacts.import.status.state')}</TH>
                        <TH className="igr:text-right">{t('contacts.import.totals.created')}</TH>
                        <TH className="igr:text-right">{t('contacts.import.totals.updated')}</TH>
                        <TH className="igr:text-right">{t('contacts.import.totals.invalid')}</TH>
                        <TH>{t('contacts.import.status.date')}</TH>
                    </TR>
                </THead>
                <TBody>
                    {imports.data.slice(0, 10).map((imp) => (
                        <TR key={imp.id}>
                            <TD>
                                <Link className="igr:font-medium igr:hover:underline" to={`/w/${workspace.id}/contactos/importar/${imp.id}`}>
                                    {imp.filename}
                                </Link>
                            </TD>
                            <TD>
                                <ImportStatusBadge status={imp.status} />
                            </TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(imp.totals.created, workspace.locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(imp.totals.updated, workspace.locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(imp.totals.invalid, workspace.locale)}</TD>
                            <TD className="igr:text-muted-foreground">{formatDate(imp.created_at, workspace.locale, workspace.timezone)}</TD>
                        </TR>
                    ))}
                </TBody>
            </Table>
        </Card>
    );
}
