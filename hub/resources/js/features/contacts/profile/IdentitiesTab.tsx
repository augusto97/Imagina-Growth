import { Fingerprint, GitMerge, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useContactAbilities } from '../abilities';
import { type ContactMerge, useContactIdentities, useRevertMerge } from '../api';
import { errorMessage } from '../errors';

export function IdentitiesTab({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const identities = useContactIdentities(workspace.id, contactId);
    const revert = useRevertMerge(workspace.id, contactId);
    const [reverting, setReverting] = useState<ContactMerge | null>(null);

    if (identities.isError) {
        return <ErrorState error={identities.error} onRetry={() => void identities.refetch()} />;
    }
    if (identities.isPending) {
        return <Skeleton className="igr:h-48" />;
    }
    const { identities: rows, merges } = identities.data;

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.identities.title')}</CardTitle>
                    <CardDescription>{t('contacts.identities.description')}</CardDescription>
                </CardHeader>
                {rows.length === 0 ? (
                    <CardContent>
                        <EmptyState icon={Fingerprint} title={t('contacts.identities.empty')} />
                    </CardContent>
                ) : (
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('contacts.identities.type')}</TH>
                                <TH>{t('contacts.identities.value')}</TH>
                                <TH className="igr:hidden igr:md:table-cell">{t('contacts.identities.site')}</TH>
                                <TH>{t('contacts.identities.firstSeen')}</TH>
                                <TH>{t('contacts.identities.lastSeen')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {rows.map((r, i) => (
                                <TR key={`${r.type}-${i}`}>
                                    <TD className="igr:font-medium">{r.label}</TD>
                                    <TD className="igr:font-mono igr:text-xs igr:text-muted-foreground">{r.display}</TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{r.site ?? '—'}</TD>
                                    <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(r.first_seen_at, workspace.locale)}</TD>
                                    <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(r.last_seen_at, workspace.locale)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.merges.title')}</CardTitle>
                    <CardDescription>{t('contacts.merges.description')}</CardDescription>
                </CardHeader>
                <CardContent>
                    {merges.length === 0 ? (
                        <p className="igr:flex igr:items-center igr:gap-2 igr:text-xs igr:text-muted-foreground">
                            <GitMerge className="igr:size-3.5" />
                            {t('contacts.merges.empty')}
                        </p>
                    ) : (
                        <ul className="igr:divide-y">
                            {merges.map((m) => (
                                <li key={m.id} className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2 igr:py-2.5 igr:first:pt-0 igr:last:pb-0">
                                    <div className="igr:min-w-0 igr:text-[13px]">
                                        <p>
                                            {m.reverted_at ? (
                                                <Link className="igr:text-primary igr:hover:underline" to={`/w/${workspace.id}/contactos/${m.loser_id}`}>
                                                    {t('contacts.merges.otherProfile')}
                                                </Link>
                                            ) : (
                                                <span className="igr:font-medium">{t('contacts.merges.otherProfile')}</span>
                                            )}{' '}
                                            <Badge variant="outline">{m.reason === 'manual' ? t('contacts.merges.reason.manual') : t('contacts.merges.reason.identity')}</Badge>
                                        </p>
                                        <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">
                                            {m.reverted_at
                                                ? t('contacts.merges.revertedOn', { date: formatDateTime(m.reverted_at, workspace.locale, workspace.timezone) })
                                                : t('contacts.merges.mergedOn', { date: formatDateTime(m.created_at, workspace.locale, workspace.timezone) })}
                                        </p>
                                    </div>
                                    {can.merge && !m.reverted_at && m.can_revert && (
                                        <Button variant="outline" size="sm" onClick={() => setReverting(m)}>
                                            <Undo2 />
                                            {t('contacts.merges.revert')}
                                        </Button>
                                    )}
                                    {!m.reverted_at && !m.can_revert && <span className="igr:text-xs igr:text-muted-foreground">{t('contacts.merges.notRevertible')}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Dialog open={reverting !== null} onOpenChange={(open) => !open && setReverting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('contacts.merges.revertTitle')}</DialogTitle>
                        <DialogDescription>{t('contacts.merges.revertDescription')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setReverting(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            loading={revert.isPending}
                            onClick={() =>
                                reverting &&
                                revert.mutate(reverting.id, {
                                    onSuccess: () => {
                                        setReverting(null);
                                        toast.success(t('contacts.merges.reverted'));
                                    },
                                    onError: (error) => toast.error(errorMessage(error, t)),
                                })
                            }
                        >
                            {t('contacts.merges.revert')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
