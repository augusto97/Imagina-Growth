import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAudit } from '@/api/queries';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate } from '@/lib/format';
import { actionLabel, changedFields, dayOf } from './logic';

/**
 * Registro de actividad: quién hizo qué y cuándo, en palabras («Publicó una automatización», no `flow.published`), por
 * día, con los campos que cambió (los datos personales llegan ocultos del servidor) y una búsqueda rápida.
 */
export function AuditPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const audit = useAudit(workspace.id);
    const [q, setQ] = useState('');
    const query = q.trim().toLowerCase();
    const rows = (audit.data ?? [])
        .map((entry) => ({ entry, label: actionLabel(t, entry.action), fields: changedFields(t, entry.changes) }))
        .filter(({ entry, label }) => query === '' || label.toLowerCase().includes(query) || (entry.user ?? '').toLowerCase().includes(query));

    return (
        <>
            <PageHeader title={t('audit.title')} description={t('audit.description')} />
            {audit.isError ? (
                <ErrorState error={audit.error} onRetry={() => void audit.refetch()} />
            ) : audit.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : audit.data.length === 0 ? (
                <EmptyState illustration="search" title={t('audit.empty')} description={t('activity.emptyHelp')} />
            ) : (
                <div className="igr:space-y-4">
                    <div className="igr:max-w-sm">
                        <label htmlFor="audit-search" className="igr:sr-only">
                            {t('activity.search')}
                        </label>
                        <Input id="audit-search" type="search" placeholder={t('activity.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    </div>
                    <Card>
                        <Table>
                            <THead>
                                <TR className="igr:hover:bg-transparent">
                                    <TH className="igr:w-24">{t('activity.time')}</TH>
                                    <TH>{t('audit.who')}</TH>
                                    <TH>{t('audit.action')}</TH>
                                    <TH>{t('activity.changed')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {rows.length === 0 ? (
                                    <TR>
                                        <TD colSpan={4} className="igr:py-8 igr:text-center igr:text-muted-foreground">
                                            {t('activity.noMatches')}
                                        </TD>
                                    </TR>
                                ) : null}
                                {rows.map(({ entry, label, fields }, i) => {
                                    const day = dayOf(entry.created_at, workspace.timezone);
                                    const newDay = i === 0 || day !== dayOf(rows[i - 1]?.entry.created_at ?? null, workspace.timezone);
                                    return (
                                        <Fragment key={entry.id}>
                                            {newDay ? (
                                                <TR className="igr:bg-muted/40 igr:hover:bg-muted/40">
                                                    <TD colSpan={4} className="igr:py-1.5 igr:text-caption igr:font-semibold igr:text-muted-foreground">
                                                        {formatDate(entry.created_at, workspace.locale, workspace.timezone)}
                                                    </TD>
                                                </TR>
                                            ) : null}
                                            <TR>
                                                <TD className="igr:whitespace-nowrap igr:text-muted-foreground igr:tabular-nums">
                                                    {entry.created_at ? new Date(entry.created_at).toLocaleTimeString(workspace.locale, { hour: 'numeric', minute: '2-digit', timeZone: workspace.timezone }) : '—'}
                                                </TD>
                                                <TD className="igr:whitespace-nowrap">{entry.user ?? <span className="igr:text-muted-foreground">{t('audit.system')}</span>}</TD>
                                                <TD className="igr:font-medium">{label}</TD>
                                                <TD className="igr:max-w-md igr:truncate igr:text-xs igr:text-muted-foreground" title={fields}>
                                                    {fields}
                                                </TD>
                                            </TR>
                                        </Fragment>
                                    );
                                })}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            )}
        </>
    );
}
