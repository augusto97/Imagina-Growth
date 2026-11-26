import { CalendarClock, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/input';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDateTime, formatMoney, formatNumber, formatRelative } from '@/lib/format';
import type { ContactListRow } from '../api';
import type { Business } from '../business';
import { rfmTone } from '../labels';
import { ConsentIcons } from './ConsentIcons';

export const TEMPERATURE_TONE = { hot: 'destructive', warm: 'warning', cold: 'secondary' } as const;

interface RowsProps {
    rows: ContactListRow[];
    business: Business;
    /** null: sin casillas (el rol no tiene acciones masivas). */
    selected: ReadonlySet<string> | null;
    onToggle: (id: string) => void;
    onTogglePage: () => void;
}

/**
 * Las filas de la lista de personas: tabla desde la tableta y tarjetas en el celular (el mismo dato, sin desbordar).
 * Tienda: grupo según sus compras, cuántas compras, total y última compra. Servicios: en qué va su lead, la temperatura,
 * el valor y la próxima cita. Las dos: por qué canales se le puede escribir.
 */
export function ContactRows(props: RowsProps) {
    return (
        <>
            <div className="igr:hidden igr:md:block">
                <ContactTable {...props} />
            </div>
            <ul className="igr:divide-y igr:md:hidden">
                {props.rows.map((c) => (
                    <MobileRow key={c.id} row={c} business={props.business} selected={props.selected} onToggle={props.onToggle} />
                ))}
            </ul>
        </>
    );
}

function ContactTable({ rows, business, selected, onToggle, onTogglePage }: RowsProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const services = business.servicesOnly;
    const pageIds = rows.map((r) => r.id);
    const chosen = selected === null ? 0 : pageIds.filter((id) => selected.has(id)).length;
    const money = (v: string | null | undefined) => formatMoney(v ?? '0', workspace.currency, workspace.locale);

    return (
        <Table>
            <THead>
                <TR>
                    {selected !== null ? (
                        <TH className="igr:w-10 igr:pr-0">
                            <Checkbox
                                aria-label={t('contacts.bulk.selectPage')}
                                checked={chosen > 0 && chosen === pageIds.length}
                                indeterminate={chosen > 0 && chosen < pageIds.length}
                                onChange={onTogglePage}
                            />
                        </TH>
                    ) : null}
                    <TH>{t('contacts.columns.person')}</TH>
                    {services ? (
                        <>
                            <TH>{t('contacts.columns.lead')}</TH>
                            <TH>{t('contacts.columns.temperature')}</TH>
                            <TH className="igr:text-right">{t('contacts.columns.value')}</TH>
                            <TH className="igr:hidden igr:lg:table-cell">{t('contacts.columns.nextAppointment')}</TH>
                        </>
                    ) : (
                        <>
                            <TH>{t('contacts.columns.group')}</TH>
                            <TH className="igr:text-right">{t('contacts.columns.orders')}</TH>
                            <TH className="igr:text-right">{t('contacts.columns.revenue')}</TH>
                            <TH className="igr:hidden igr:lg:table-cell">{t('contacts.columns.lastOrder')}</TH>
                            {business.services ? <TH className="igr:hidden igr:xl:table-cell">{t('contacts.columns.lead')}</TH> : null}
                        </>
                    )}
                    <TH className="igr:hidden igr:lg:table-cell">{t('contacts.columns.channels')}</TH>
                    <TH className="igr:hidden igr:xl:table-cell">{t('contacts.columns.city')}</TH>
                </TR>
            </THead>
            <TBody>
                {rows.map((c) => {
                    const isSelected = selected?.has(c.id) ?? false;
                    return (
                        <TR
                            key={c.id}
                            className={cn('igr:cursor-pointer', isSelected && 'igr:bg-brand-soft/50 igr:hover:bg-brand-soft/70')}
                            onClick={() => void navigate(`/w/${workspace.id}/contactos/${c.id}`)}
                        >
                            {selected !== null ? (
                                <TD className="igr:w-10 igr:pr-0" onClick={(e) => e.stopPropagation()}>
                                    <Checkbox aria-label={t('contacts.bulk.selectRow', { name: c.name || t('contacts.noName') })} checked={isSelected} onChange={() => onToggle(c.id)} />
                                </TD>
                            ) : null}
                            <TD className="igr:max-w-80">
                                <PersonCell row={c} />
                            </TD>
                            {services ? (
                                <>
                                    <TD>{c.lead ? <Badge variant="outline">{c.lead.stage_label}</Badge> : <Muted>{t('contacts.list.noLead')}</Muted>}</TD>
                                    <TD>{c.lead ? <Badge variant={TEMPERATURE_TONE[c.lead.temperature]}>{t(`contacts.temperature.${c.lead.temperature}`)}</Badge> : <Muted>—</Muted>}</TD>
                                    <TD className="igr:text-right igr:whitespace-nowrap igr:tabular-nums">{c.lead?.value ? money(c.lead.value) : <Muted>—</Muted>}</TD>
                                    <TD className="igr:hidden igr:whitespace-nowrap igr:lg:table-cell">
                                        {c.next_appointment_at ? formatDateTime(c.next_appointment_at, workspace.locale, workspace.timezone) : <Muted>—</Muted>}
                                    </TD>
                                </>
                            ) : (
                                <>
                                    <TD>
                                        {c.rfm_segment ? (
                                            <Badge variant={rfmTone(c.rfm_segment)} title={t(`contacts.rfmHelp.${c.rfm_segment}`, { defaultValue: '' })}>
                                                {t(`contacts.rfm.${c.rfm_segment}`, { defaultValue: c.rfm_segment })}
                                            </Badge>
                                        ) : (
                                            <Muted>{c.orders_count > 0 ? '—' : t('contacts.list.noPurchases')}</Muted>
                                        )}
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(c.orders_count, workspace.locale)}</TD>
                                    <TD className="igr:text-right igr:font-medium igr:whitespace-nowrap igr:tabular-nums">{money(c.revenue_total_ws)}</TD>
                                    <TD className="igr:hidden igr:whitespace-nowrap igr:text-muted-foreground igr:lg:table-cell">
                                        {c.last_order_at ? formatRelative(c.last_order_at, workspace.locale) : '—'}
                                    </TD>
                                    {business.services ? (
                                        <TD className="igr:hidden igr:xl:table-cell">{c.lead ? <Badge variant="outline">{c.lead.stage_label}</Badge> : <Muted>—</Muted>}</TD>
                                    ) : null}
                                </>
                            )}
                            <TD className="igr:hidden igr:lg:table-cell">
                                <ConsentIcons granted={c.consents ?? []} />
                            </TD>
                            <TD className="igr:hidden igr:max-w-40 igr:truncate igr:text-muted-foreground igr:xl:table-cell">{c.city ?? '—'}</TD>
                        </TR>
                    );
                })}
            </TBody>
        </Table>
    );
}

function PersonCell({ row }: { row: ContactListRow }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const contact = [row.email, row.phone].filter(Boolean).join(' · ');
    return (
        <div className="igr:flex igr:min-w-0 igr:items-center igr:gap-3">
            <Avatar name={row.name} seed={row.id} size="sm" />
            <div className="igr:min-w-0">
                <Link
                    to={`/w/${workspace.id}/contactos/${row.id}`}
                    className="igr:block igr:truncate igr:font-medium igr:outline-none igr:hover:underline igr:focus-visible:underline"
                    onClick={(e) => e.stopPropagation()}
                >
                    {row.name !== '' ? row.name : t('contacts.noName')}
                </Link>
                <p className="igr:truncate igr:text-caption igr:text-muted-foreground" title={contact}>
                    {contact || '—'}
                </p>
            </div>
        </div>
    );
}

function MobileRow({ row, business, selected, onToggle }: { row: ContactListRow; business: Business; selected: ReadonlySet<string> | null; onToggle: (id: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const isSelected = selected?.has(row.id) ?? false;
    let summary: ReactNode;
    if (business.servicesOnly) {
        summary = row.lead ? (
            <>
                <Badge variant={TEMPERATURE_TONE[row.lead.temperature]}>{t(`contacts.temperature.${row.lead.temperature}`)}</Badge>
                <span className="igr:truncate">{row.lead.stage_label}</span>
            </>
        ) : (
            <span>{t('contacts.list.noLead')}</span>
        );
    } else {
        summary = (
            <>
                {row.rfm_segment ? <Badge variant={rfmTone(row.rfm_segment)}>{t(`contacts.rfm.${row.rfm_segment}`, { defaultValue: row.rfm_segment })}</Badge> : null}
                <span className="igr:truncate igr:tabular-nums">
                    {row.orders_count > 0
                        ? t('contacts.list.purchasesSummary', { count: row.orders_count, total: formatMoney(row.revenue_total_ws, workspace.currency, workspace.locale) })
                        : t('contacts.list.noPurchases')}
                </span>
            </>
        );
    }
    return (
        <li className={cn('igr:flex igr:items-center igr:gap-3 igr:px-3 igr:py-2.5', isSelected && 'igr:bg-brand-soft/50')}>
            {selected !== null ? (
                <label className="igr:-m-2 igr:flex igr:p-2">
                    <Checkbox aria-label={t('contacts.bulk.selectRow', { name: row.name || t('contacts.noName') })} checked={isSelected} onChange={() => onToggle(row.id)} />
                </label>
            ) : null}
            <Link to={`/w/${workspace.id}/contactos/${row.id}`} className="igr:flex igr:min-w-0 igr:flex-1 igr:items-center igr:gap-3 igr:outline-none igr:focus-visible:underline">
                <Avatar name={row.name} seed={row.id} size="md" />
                <span className="igr:min-w-0 igr:flex-1">
                    <span className="igr:block igr:truncate igr:font-medium">{row.name !== '' ? row.name : t('contacts.noName')}</span>
                    <span className="igr:mt-0.5 igr:flex igr:min-w-0 igr:items-center igr:gap-1.5 igr:text-caption igr:text-muted-foreground">{summary}</span>
                    {business.services && row.next_appointment_at ? (
                        <span className="igr:mt-0.5 igr:flex igr:items-center igr:gap-1 igr:text-caption igr:text-muted-foreground">
                            <CalendarClock className="igr:size-3.5" aria-hidden />
                            {formatDateTime(row.next_appointment_at, workspace.locale, workspace.timezone)}
                        </span>
                    ) : null}
                </span>
                <ConsentIcons granted={row.consents ?? []} className="igr:shrink-0" />
                <ChevronRight className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
            </Link>
        </li>
    );
}

function Muted({ children }: { children: ReactNode }) {
    return <span className="igr:text-muted-foreground">{children}</span>;
}
