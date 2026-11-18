import { CircleAlert, Pause, Play, RefreshCw, ShieldCheck, Trash2, UsersRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { PlatformMark } from '../bits';
import { type Audience, type AudiencePreset, type AudiencePurpose, type AudiencesResponse, useAudiences, useCreateAudience, useDeleteAudience, useSyncAudience, useUpdateAudience } from './api';

const PURPOSES: AudiencePurpose[] = ['target', 'exclusion', 'lookalike_seed'];

/** Medición → Audiencias (M06, Fase 3): segmentos sincronizados con Meta, Google y TikTok cada 12 h. */
export function AudiencesTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const audiences = useAudiences(workspace.id);

    if (audiences.isPending) {
        return <Skeleton className="igr:h-48" />;
    }
    if (audiences.isError) {
        return <ErrorState error={audiences.error} onRetry={() => void audiences.refetch()} />;
    }
    const data = audiences.data;
    const usable = data.destinations.filter((d) => d.active && d.missing === null);

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:items-start igr:gap-2 igr:rounded-lg igr:border igr:p-3 igr:text-[13px]">
                <ShieldCheck aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" />
                <div>
                    <p className="igr:font-medium">{t(`audiences.consent.${data.consent_rule}.title`)}</p>
                    <p className="igr:mt-0.5 igr:text-muted-foreground">{t(`audiences.consent.${data.consent_rule}.help`)}</p>
                </div>
            </div>
            {data.destinations.length === 0 ? (
                <EmptyState icon={UsersRound} title={t('audiences.noDestinations.title')} description={t('audiences.noDestinations.description')} />
            ) : (
                <>
                    <Creator data={data} usable={usable} />
                    {data.data.length === 0 ? (
                        <EmptyState icon={UsersRound} title={t('audiences.empty.title')} description={t('audiences.empty.description')} />
                    ) : (
                        <AudienceTable data={data} />
                    )}
                </>
            )}
        </div>
    );
}

function Creator({ data, usable }: { data: AudiencesResponse; usable: AudiencesResponse['destinations'] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const create = useCreateAudience(workspace.id);
    const [destination, setDestination] = useState(usable[0]?.id ?? '');
    const [segment, setSegment] = useState('');
    const [purpose, setPurpose] = useState<AudiencePurpose>('target');
    const [name, setName] = useState('');
    const blocked = data.destinations.filter((d) => d.missing !== null);
    if (!canManage) return null;

    const run = (input: { preset?: AudiencePreset; segment_id?: string; purpose?: AudiencePurpose; name?: string }) => {
        if (destination === '') return;
        create.mutate(
            { destination_id: destination, ...input },
            {
                onSuccess: () => {
                    toast.success(t('audiences.created'));
                    setSegment('');
                    setName('');
                },
                onError: (e) => toast.error(t('errors.title'), isApiError(e) ? (Object.values(e.fieldErrors)[0] ?? e.message) : undefined),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('audiences.create.title')}</CardTitle>
                <CardDescription>{t('audiences.create.help')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                {usable.length === 0 ? (
                    <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-[13px] igr:text-muted-foreground">
                        <CircleAlert aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0" />
                        {t('audiences.create.noUsable')}
                    </p>
                ) : (
                    <>
                        <div className="igr:max-w-xs igr:space-y-1.5">
                            <Label htmlFor="aud-destination">{t('audiences.create.destination')}</Label>
                            <Select id="aud-destination" value={destination} onChange={(e) => setDestination(e.target.value)}>
                                {usable.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name ?? d.platform}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                            {data.presets.map((p) => (
                                <div key={p.key} className="igr:flex igr:flex-col igr:justify-between igr:gap-3 igr:rounded-lg igr:border igr:p-3">
                                    <div>
                                        <p className="igr:text-[13px] igr:font-medium">{t(`audiences.preset.${p.key}.title`)}</p>
                                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t(`audiences.preset.${p.key}.help`)}</p>
                                    </div>
                                    <Button variant="outline" size="sm" loading={create.isPending && create.variables.preset === p.key} onClick={() => run({ preset: p.key })}>
                                        {t('audiences.create.usePreset')}
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <form
                            className="igr:grid igr:items-end igr:gap-3 igr:md:grid-cols-[1fr_180px_1fr_auto]"
                            onSubmit={(e) => {
                                e.preventDefault();
                                run({ segment_id: segment, purpose, name: name.trim() || undefined });
                            }}
                        >
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="aud-segment">{t('audiences.create.segment')}</Label>
                                <Select id="aud-segment" value={segment} onChange={(e) => setSegment(e.target.value)}>
                                    <option value="">{t('audiences.create.chooseSegment')}</option>
                                    {data.segments.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {t('audiences.create.segmentOption', { name: s.name, count: s.members })}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="aud-purpose">{t('audiences.create.purpose')}</Label>
                                <Select id="aud-purpose" value={purpose} onChange={(e) => setPurpose(e.target.value as AudiencePurpose)}>
                                    {PURPOSES.map((p) => (
                                        <option key={p} value={p}>
                                            {t(`audiences.purpose.${p}`)}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="aud-name">{t('audiences.create.name')}</Label>
                                <Input id="aud-name" value={name} placeholder={t('audiences.create.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
                            </div>
                            <Button type="submit" disabled={segment === ''} loading={create.isPending && create.variables.segment_id !== undefined}>
                                {t('audiences.create.submit')}
                            </Button>
                        </form>
                    </>
                )}
                {blocked.length > 0 ? (
                    <ul className="igr:space-y-1 igr:text-xs igr:text-muted-foreground">
                        {blocked.map((d) => (
                            <li key={d.id}>
                                <span className="igr:font-medium">{d.name ?? d.platform}:</span> {d.missing}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </CardContent>
        </Card>
    );
}

function AudienceTable({ data }: { data: AudiencesResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const sync = useSyncAudience(workspace.id);
    const update = useUpdateAudience(workspace.id);
    const remove = useDeleteAudience(workspace.id);
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? (Object.values(e.fieldErrors)[0] ?? e.message) : undefined);

    return (
        <Card>
            <CardContent className="igr:overflow-x-auto igr:p-0">
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('audiences.table.audience')}</TH>
                            <TH>{t('audiences.table.purpose')}</TH>
                            <TH>{t('audiences.table.status')}</TH>
                            <TH className="igr:text-right">{t('audiences.table.members')}</TH>
                            <TH>{t('audiences.table.lastSync')}</TH>
                            {canManage ? <TH className="igr:w-0" /> : null}
                        </TR>
                    </THead>
                    <TBody>
                        {data.data.map((a) => (
                            <TR key={a.id}>
                                <TD className="igr:min-w-56">
                                    <div className="igr:flex igr:items-center igr:gap-2">
                                        {a.platform ? <PlatformMark platform={a.platform} /> : null}
                                        <div className="igr:min-w-0">
                                            <p className="igr:truncate igr:font-medium">{a.name}</p>
                                            <p className="igr:truncate igr:text-xs igr:text-muted-foreground">{t('audiences.table.from', { segment: a.segment?.name ?? '—', destination: a.destination?.name ?? '—' })}</p>
                                        </div>
                                    </div>
                                </TD>
                                <TD className="igr:whitespace-nowrap">{t(`audiences.purpose.${a.purpose}`)}</TD>
                                <TD className="igr:max-w-72">
                                    <StatusBadge audience={a} />
                                    {a.last_error ? <p className="igr:mt-1 igr:text-xs igr:text-destructive">{a.last_error}</p> : null}
                                </TD>
                                <TD className="igr:text-right igr:whitespace-nowrap igr:tabular-nums">
                                    {formatNumber(a.members_count, workspace.locale)}
                                    <span className="igr:block igr:text-[11px] igr:text-muted-foreground">
                                        {a.last_synced_at ? t('audiences.table.delta', { added: a.last_added, removed: a.last_removed }) : null}
                                    </span>
                                    {a.skipped_no_consent > 0 ? <span className="igr:block igr:text-[11px] igr:text-muted-foreground">{t('audiences.table.skipped', { count: a.skipped_no_consent })}</span> : null}
                                    {a.last_synced_at && a.members_count < data.min_recommended_size ? <span className="igr:block igr:text-[11px] igr:text-warning">{t('audiences.table.small', { min: data.min_recommended_size })}</span> : null}
                                </TD>
                                <TD className="igr:whitespace-nowrap igr:text-xs igr:text-muted-foreground">
                                    {a.last_synced_at ? formatRelative(a.last_synced_at, workspace.locale) : t('audiences.table.never')}
                                    {a.next_sync_at ? <span className="igr:block">{t('audiences.table.next', { when: formatRelative(a.next_sync_at, workspace.locale) })}</span> : null}
                                </TD>
                                {canManage ? (
                                    <TD>
                                        <div className="igr:flex igr:gap-1">
                                            <Button variant="ghost" size="icon" aria-label={t('audiences.table.syncNow')} disabled={a.status === 'paused'} onClick={() => sync.mutate(a.id, { onSuccess: () => toast.success(t('audiences.table.queued')), onError })}>
                                                <RefreshCw />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={a.status === 'paused' ? t('audiences.table.resume') : t('audiences.table.pause')}
                                                onClick={() => update.mutate({ id: a.id, input: { status: a.status === 'paused' ? 'active' : 'paused' } }, { onError })}
                                            >
                                                {a.status === 'paused' ? <Play /> : <Pause />}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t('audiences.table.delete')}
                                                onClick={() => {
                                                    if (window.confirm(t('audiences.table.deleteConfirm', { name: a.name }))) {
                                                        remove.mutate(a.id, { onError });
                                                    }
                                                }}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TD>
                                ) : null}
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function StatusBadge({ audience }: { audience: Audience }) {
    const { t } = useTranslation();
    const variant = audience.status === 'active' ? 'success' : audience.status === 'error' ? 'destructive' : audience.status === 'paused' ? 'outline' : 'warning';
    return <Badge variant={variant}>{t(`audiences.status.${audience.status}`)}</Badge>;
}
