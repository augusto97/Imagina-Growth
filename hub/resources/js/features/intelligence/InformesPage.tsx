import { CalendarClock, CircleCheck, Download, FileChartColumn, Mail, RefreshCw, Send, Sparkles, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Callout } from '@/components/ui/callout';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { clientReportPdfUrl, useGenerateClientReport, useResendClientReport } from '@/features/agency/api';
import { makeFormatters, type Formatters } from '@/features/analytics/format';
import { SeverityBadge } from '@/features/insights/severity';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { WeeklyReports } from './ReportsPage';
import { type MonthlyFacts, type Recipients, useMonthlyPreview, useMonthlyReport, useMonthlyReports } from './reportsApi';

/*
 * Resultados → Informes: lo que le llega al dueño sin entrar al panel. El mensual con marca (el dinero que generó la
 * plataforma, para compartir: vista previa, PDF y a quién llega) y el semanal del analista (5 cifras, 3
 * recomendaciones y 3 acciones). `?tipo=semanal` abre el semanal; `inteligencia/informes` es la misma página.
 */
const KINDS = ['mensual', 'semanal'] as const;
type Kind = (typeof KINDS)[number];

export function InformesPage({ defaultKind = 'mensual' }: { defaultKind?: Kind }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [search, setSearch] = useSearchParams();
    const raw = search.get('tipo');
    const kind: Kind = (KINDS as readonly string[]).includes(raw ?? '') ? (raw as Kind) : defaultKind;
    const index = useMonthlyReports(workspace.id);
    const setKind = (next: string) => {
        const p = new URLSearchParams();
        p.set('tipo', next);
        setSearch(p, { replace: true });
    };

    return (
        <>
            <PageHeader title={t('intelligence.hub.title')} description={t('intelligence.hub.description')} icon={FileChartColumn} tone="emerald" />
            <Tabs value={kind} onValueChange={setKind}>
                <TabsList>
                    <TabsTrigger value="mensual">{t('intelligence.hub.monthly')}</TabsTrigger>
                    <TabsTrigger value="semanal">{t('intelligence.hub.weekly')}</TabsTrigger>
                </TabsList>
                <TabsContent value="mensual" className="igr:mt-4">
                    {index.isError ? <ErrorState error={index.error} onRetry={() => void index.refetch()} /> : <MonthlyTab />}
                </TabsContent>
                <TabsContent value="semanal" className="igr:mt-4">
                    <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_300px]">
                        <div className="igr:min-w-0">
                            <WeeklyReports />
                        </div>
                        <aside className="igr:space-y-4">
                            <AboutCard kind="weekly" recipients={index.data?.recipients.weekly} />
                        </aside>
                    </div>
                </TabsContent>
            </Tabs>
        </>
    );
}

/** `inteligencia/informes` (la URL de siempre de los informes del analista): la misma pantalla en la pestaña semanal. */
export function WeeklyInformesPage() {
    return <InformesPage defaultKind="semanal" />;
}

/** Qué incluye, cuándo llega y a quién (los dueños que no lo apagaron en sus avisos). */
function AboutCard({ kind, recipients }: { kind: 'monthly' | 'weekly'; recipients: Recipients | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const items = Object.values(t(`intelligence.hub.${kind}About.includes`, { returnObjects: true }) as Record<string, string>);
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('intelligence.hub.includesTitle')}</CardTitle>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                <ul className="igr:space-y-2 igr:text-support">
                    {items.map((item) => (
                        <li key={item} className="igr:flex igr:gap-2">
                            <CircleCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
                <div className="igr:flex igr:gap-2 igr:border-t igr:pt-4 igr:text-support">
                    <CalendarClock className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <p>
                        <span className="igr:font-medium">{t('intelligence.hub.when')}</span> {t(`intelligence.hub.${kind}About.when`)}
                    </p>
                </div>
                <div className="igr:flex igr:gap-2 igr:text-support">
                    <Users className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <div className="igr:min-w-0">
                        <p className="igr:font-medium">{t('intelligence.hub.who')}</p>
                        {recipients === undefined ? (
                            <Skeleton className="igr:mt-1 igr:h-4 igr:w-32" />
                        ) : recipients.count === 0 ? (
                            <p className="igr:text-muted-foreground">{t('intelligence.hub.nobody')}</p>
                        ) : (
                            <ul className="igr:mt-0.5 igr:text-muted-foreground">
                                {recipients.masked.map((m) => (
                                    <li key={m} className="igr:truncate">
                                        {m}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">
                            {t('intelligence.hub.whoHelp')}{' '}
                            <Link to={`/w/${workspace.id}/equipo`} className="igr:font-medium igr:text-primary igr:hover:underline">
                                {t('intelligence.hub.team')}
                            </Link>
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function MonthlyTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const index = useMonthlyReports(workspace.id);
    const [search, setSearch] = useSearchParams();
    const reports = index.data?.reports ?? [];
    const selectedId = search.get('informe') ?? reports[0]?.id ?? null;
    const detail = useMonthlyReport(workspace.id, selectedId);
    const preview = useMonthlyPreview(workspace.id, index.isSuccess && reports.length === 0);
    const generate = useGenerateClientReport(workspace.id);
    const resend = useResendClientReport(workspace.id);
    const canManage = index.data?.can_manage ?? false;
    const fmt = makeFormatters(workspace.locale, workspace.currency);
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
    const select = (id: string) => {
        const next = new URLSearchParams(search);
        next.set('informe', id);
        setSearch(next, { replace: true });
    };

    if (index.isPending) {
        return <Skeleton className="igr:h-96 igr:rounded-xl" />;
    }
    const lastMonth = index.data?.last_month ?? '';
    const report = detail.data;
    const facts = report?.facts ?? preview.data?.facts;
    const generateLast = canManage ? (
        <Button
            variant={reports.length === 0 ? 'default' : 'outline'}
            loading={generate.isPending}
            onClick={() =>
                generate.mutate(
                    { month: lastMonth, send: false },
                    {
                        onSuccess: (res) => {
                            toast.success(t('intelligence.hub.generatedOk'));
                            select(res.report.id);
                        },
                        onError,
                    },
                )
            }
        >
            <RefreshCw aria-hidden />
            {t('intelligence.hub.generate', { month: preview.data?.facts.mes ?? reports.find((r) => r.period === lastMonth)?.month ?? lastMonth })}
        </Button>
    ) : null;

    return (
        <div className="igr:space-y-6">
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_300px]">
                <div className="igr:min-w-0">
                    {reports.length === 0 ? (
                        <Callout tone="brand" className="igr:mb-4" title={t('intelligence.hub.previewTitle')}>
                            {t('intelligence.hub.previewHelp')}
                        </Callout>
                    ) : null}
                    {facts === undefined ? (
                        preview.isError ? (
                            <ErrorState error={preview.error} onRetry={() => void preview.refetch()} />
                        ) : (
                            <Skeleton className="igr:h-96 igr:rounded-xl" />
                        )
                    ) : (
                        <ReportDocument
                            facts={facts}
                            fmt={fmt}
                            summary={report?.summary ?? null}
                            source={report?.summary_source ?? null}
                            status={
                                report ? (
                                    <Badge variant={report.sent_at ? 'success' : 'outline'}>
                                        {report.sent_at ? t('intelligence.hub.sentAt', { when: formatRelative(report.sent_at, workspace.locale) }) : t('intelligence.hub.notSent')}
                                    </Badge>
                                ) : (
                                    <Badge variant="brand">{t('intelligence.hub.draft')}</Badge>
                                )
                            }
                            actions={
                                <>
                                    {report?.has_pdf ? (
                                        <Button asChild>
                                            <a href={clientReportPdfUrl(workspace.id, report.id)} target="_blank" rel="noreferrer">
                                                <Download aria-hidden />
                                                {t('intelligence.hub.download')}
                                            </a>
                                        </Button>
                                    ) : null}
                                    {report && canManage ? (
                                        <Button
                                            variant="outline"
                                            loading={resend.isPending}
                                            onClick={() =>
                                                resend.mutate(report.id, {
                                                    onSuccess: (res) => toast.success(t('intelligence.hub.sentOk', { count: res.sent.count })),
                                                    onError,
                                                })
                                            }
                                        >
                                            <Send aria-hidden />
                                            {report.sent_at ? t('intelligence.hub.resend') : t('intelligence.hub.send')}
                                        </Button>
                                    ) : null}
                                    {report === undefined ? generateLast : null}
                                </>
                            }
                        />
                    )}
                </div>
                <aside className="igr:space-y-4">
                    <AboutCard kind="monthly" recipients={index.data?.recipients.monthly} />
                </aside>
            </div>

            {reports.length > 0 ? (
                <section aria-labelledby="past-reports">
                    <div className="igr:mb-3 igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                        <h2 id="past-reports" className="igr:text-section igr:font-semibold">
                            {t('intelligence.hub.history')}
                        </h2>
                        {generateLast}
                    </div>
                    <Card className="igr:overflow-hidden">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('intelligence.hub.month')}</TH>
                                    <TH className="igr:text-right">{t('intelligence.hub.generatedCol')}</TH>
                                    <TH className="igr:text-right">{t('intelligence.hub.changeCol')}</TH>
                                    <TH>{t('intelligence.hub.statusCol')}</TH>
                                    <TH>
                                        <span className="igr:sr-only">{t('intelligence.hub.download')}</span>
                                    </TH>
                                </TR>
                            </THead>
                            <TBody>
                                {reports.map((r) => (
                                    <TR key={r.id} className={cn(r.id === selectedId && 'igr:bg-muted/50')}>
                                        <TD className="igr:font-medium igr:first-letter:uppercase">
                                            <button type="button" className="igr:hover:underline igr:focus-visible:underline igr:outline-none" onClick={() => select(r.id)} aria-current={r.id === selectedId ? 'true' : undefined}>
                                                {r.month || r.period}
                                            </button>
                                        </TD>
                                        <TD className="igr:text-right igr:tabular-nums">{fmt.money(r.generated)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{r.change_pct === null ? '—' : `${r.change_pct > 0 ? '+' : ''}${fmt.decimal(r.change_pct, 1)} %`}</TD>
                                        <TD>
                                            <Badge variant={r.sent_at ? 'success' : 'outline'}>
                                                {r.sent_at ? t('intelligence.hub.sentAt', { when: formatRelative(r.sent_at, workspace.locale) }) : t('intelligence.hub.notSent')}
                                            </Badge>
                                        </TD>
                                        <TD className="igr:text-right">
                                            {r.has_pdf ? (
                                                <Button asChild size="sm" variant="ghost">
                                                    <a href={clientReportPdfUrl(workspace.id, r.id)} target="_blank" rel="noreferrer" aria-label={t('intelligence.hub.downloadMonth', { month: r.month || r.period })}>
                                                        <Download aria-hidden />
                                                        PDF
                                                    </a>
                                                </Button>
                                            ) : null}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </section>
            ) : null}
        </div>
    );
}

/** El informe como lo va a leer el dueño: el dinero generado, de dónde salió, las cifras del mes y qué hacer. */
function ReportDocument({
    facts,
    fmt,
    summary,
    source,
    status,
    actions,
}: {
    facts: MonthlyFacts;
    fmt: Formatters;
    summary: string | null;
    source: 'ai' | 'fallback' | null;
    status: ReactNode;
    actions: ReactNode;
}) {
    const { t } = useTranslation();
    const money = facts.dinero_generado;
    const modules = [...money.por_modulo].sort((a, b) => b.ventas - a.ventas);
    const max = modules[0]?.ventas ?? 0;
    const can = useCan('viewDashboards');
    return (
        <Card className="igr:overflow-hidden">
            <div className="igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3 igr:border-b igr:bg-muted/30 igr:px-5 igr:py-4">
                <div className="igr:min-w-0">
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{facts.negocio}</p>
                    <h2 className="igr:text-section igr:font-semibold igr:first-letter:uppercase">{t('intelligence.hub.docTitle', { month: facts.mes })}</h2>
                </div>
                {status}
            </div>
            <div className="igr:space-y-6 igr:p-5">
                <div>
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('intelligence.hub.generatedLabel')}</p>
                    <p className="igr:text-[34px] igr:leading-10 igr:font-semibold igr:tracking-tight igr:tabular-nums">{fmt.money(money.total)}</p>
                    <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">
                        {facts.modo === 'tienda'
                            ? t('intelligence.hub.generatedStore', { count: money.pedidos, formatted: fmt.number(money.pedidos), share: fmt.percent(money.participacion_pct / 100, 1), sales: fmt.money(money.ventas_tienda) })
                            : t('intelligence.hub.generatedServices', { count: money.pedidos, formatted: fmt.number(money.pedidos), sales: fmt.money(money.ventas_tienda) })}{' '}
                        {money.cambio_vs_anterior_pct !== null
                            ? t(money.cambio_vs_anterior_pct >= 0 ? 'intelligence.hub.up' : 'intelligence.hub.down', { change: `${fmt.decimal(Math.abs(money.cambio_vs_anterior_pct), 1)} %` })
                            : ''}
                    </p>
                </div>
                {modules.length > 0 ? (
                    <div>
                        <h3 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('intelligence.hub.byModule')}</h3>
                        <ul className="igr:space-y-2">
                            {modules.map((m) => (
                                <li key={m.clave}>
                                    <div className="igr:flex igr:justify-between igr:gap-3 igr:text-[13px]">
                                        <span>{m.modulo}</span>
                                        <span className="igr:font-medium igr:tabular-nums">{fmt.money(m.ventas)}</span>
                                    </div>
                                    <div className="igr:mt-1 igr:h-1.5 igr:rounded-full igr:bg-muted" aria-hidden>
                                        <div className="igr:h-1.5 igr:rounded-full igr:bg-primary" style={{ width: `${max > 0 ? Math.max(2, (m.ventas / max) * 100) : 0}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <p className="igr:text-support igr:text-muted-foreground">{t('intelligence.hub.noModules')}</p>
                )}
                {summary ? (
                    <div className="igr:rounded-lg igr:border igr:bg-card igr:p-4">
                        <h3 className="igr:mb-1.5 igr:text-[13px] igr:font-semibold">{t('intelligence.hub.summary')}</h3>
                        <p className="igr:text-support igr:whitespace-pre-line">{summary}</p>
                        {source === 'ai' ? (
                            <p className="igr:mt-2 igr:flex igr:items-center igr:gap-1 igr:text-caption igr:text-muted-foreground">
                                <Sparkles className="igr:size-3" aria-hidden />
                                {t('intelligence.hub.aiNote')}
                            </p>
                        ) : null}
                    </div>
                ) : null}
                <div>
                    <h3 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('intelligence.hub.kpis')}</h3>
                    <dl className="igr:flex igr:flex-wrap igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border">
                        {facts.kpis.map((k) => (
                            <div key={k.clave} className="igr:min-w-36 igr:flex-1 igr:basis-36 igr:bg-card igr:px-3 igr:py-2.5">
                                <dt className="igr:truncate igr:text-caption igr:text-muted-foreground">{k.nombre}</dt>
                                <dd className="igr:text-[15px] igr:font-semibold igr:tabular-nums">{k.unidad === 'money' ? fmt.money(k.valor) : fmt.number(k.valor)}</dd>
                                <dd className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">
                                    {k.cambio_pct === null ? '—' : `${k.cambio_pct > 0 ? '+' : ''}${fmt.decimal(k.cambio_pct, 1)} % ${t('intelligence.hub.vsPrevious')}`}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
                {facts.hallazgos.length > 0 && can ? (
                    <div>
                        <h3 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('intelligence.hub.todo')}</h3>
                        <ul className="igr:divide-y igr:rounded-lg igr:border">
                            {facts.hallazgos.map((h) => (
                                <li key={h.titulo} className="igr:flex igr:flex-wrap igr:items-start igr:gap-x-3 igr:gap-y-1 igr:px-3 igr:py-2.5 igr:text-[13px]">
                                    <SeverityBadge severity={h.severidad} />
                                    <span className="igr:min-w-0 igr:flex-1 igr:basis-48">{h.titulo}</span>
                                    {h.impacto ? <span className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">{t('intelligence.hub.atStake', { amount: fmt.money(h.impacto) })}</span> : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:pt-4">
                    <Mail className="igr:size-4 igr:text-muted-foreground" aria-hidden />
                    <span className="igr:mr-auto igr:text-caption igr:text-muted-foreground">{t('intelligence.hub.pdfHelp')}</span>
                    {actions}
                </div>
            </div>
        </Card>
    );
}
