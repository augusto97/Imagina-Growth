import { useMutation, useQueryClient } from '@tanstack/react-query';
import { MailPlus, ShieldCheck, ShieldOff, Trash2, UserPlus, Users } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { keys, useMembers, wsPath } from '@/api/queries';
import type { WorkspaceRole } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { api, isApiError } from '@/lib/api';
import { formatDate, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';

const ROLES: WorkspaceRole[] = ['owner', 'admin', 'marketing', 'agent', 'viewer'];

function firstError(error: unknown, fallback: string): string {
    if (isApiError(error)) {
        return Object.values(error.fieldErrors)[0] ?? (error.message !== '' ? error.message : fallback);
    }
    return fallback;
}

function InviteDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const [email, setEmail] = useState('');
    const [role, setRole] = useState<WorkspaceRole>('marketing');
    const [error, setError] = useState<string | undefined>();
    const invite = useMutation({ mutationFn: () => api.post(`${wsPath(workspace.id)}/invitations`, { email: email.trim(), role }) });
    const roles = workspace.role === 'owner' ? ROLES : ROLES.filter((r) => r !== 'owner');

    const submit = (event: SyntheticEvent) => {
        event.preventDefault();
        setError(undefined);
        invite.mutate(undefined, {
            onSuccess: () => {
                toast.success(t('team.invited', { email: email.trim() }));
                void qc.invalidateQueries({ queryKey: keys.members(workspace.id) });
                setEmail('');
                onOpenChange(false);
            },
            onError: (e) => setError(firstError(e, t('errors.generic'))),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t('team.inviteTitle')}</DialogTitle>
                        <DialogDescription>{t('team.inviteDescription', { workspace: workspace.name })}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:space-y-4">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="invite-email">{t('auth.email')}</Label>
                            <Input id="invite-email" type="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={error ? true : undefined} />
                            <FieldError message={error} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="invite-role">{t('team.role')}</Label>
                            <Select id="invite-role" value={role} onChange={(e) => setRole(e.target.value as WorkspaceRole)}>
                                {roles.map((r) => (
                                    <option key={r} value={r}>
                                        {t(`roles.${r}`)}
                                    </option>
                                ))}
                            </Select>
                            <p className="igr:text-xs igr:text-muted-foreground">{t(`roleHelp.${role}`)}</p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={invite.isPending} disabled={email.trim() === ''}>
                            <MailPlus />
                            {t('team.sendInvite')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function TeamPage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const qc = useQueryClient();
    const members = useMembers(workspace.id);
    const canManage = useCan('manageMembers');
    const [params, setParams] = useSearchParams();
    const inviteOpen = canManage && params.get('invitar') === '1';
    const setInviteOpen = (open: boolean) => {
        const p = new URLSearchParams(params);
        if (open) {
            p.set('invitar', '1');
        } else {
            p.delete('invitar');
        }
        setParams(p, { replace: true });
    };
    const base = wsPath(workspace.id);
    const refresh = () => void qc.invalidateQueries({ queryKey: keys.members(workspace.id) });

    const changeRole = useMutation({
        mutationFn: (v: { id: string; role: WorkspaceRole }) => api.patch(`${base}/members/${v.id}`, { role: v.role }),
        onSuccess: () => {
            toast.success(t('team.roleChanged'));
            refresh();
        },
        onError: (e) => {
            toast.error(t('team.cannotChange'), firstError(e, t('errors.generic')));
            refresh();
        },
    });
    const remove = useMutation({
        mutationFn: (id: string) => api.delete(`${base}/members/${id}`),
        onSuccess: () => {
            toast.info(t('team.removed'));
            refresh();
        },
        onError: (e) => toast.error(t('team.cannotChange'), firstError(e, t('errors.generic'))),
    });
    const revoke = useMutation({
        mutationFn: (id: string) => api.delete(`${base}/invitations/${id}`),
        onSuccess: refresh,
    });

    return (
        <>
            <PageHeader
                title={t('team.title')}
                description={t('team.description')}
                actions={
                    canManage ? (
                        <Button onClick={() => setInviteOpen(true)}>
                            <UserPlus />
                            {t('team.invite')}
                        </Button>
                    ) : undefined
                }
            />
            {members.isError ? (
                <ErrorState error={members.error} onRetry={() => void members.refetch()} />
            ) : members.isPending ? (
                <Skeleton className="igr:h-48" />
            ) : (
                <div className="igr:space-y-4">
                    <Card>
                        <Table>
                            <THead>
                                <TR className="igr:hover:bg-transparent">
                                    <TH>{t('team.person')}</TH>
                                    <TH>{t('team.role')}</TH>
                                    <TH>{t('team.twoFactor')}</TH>
                                    <TH>{t('team.lastLogin')}</TH>
                                    <TH className="igr:w-10" />
                                </TR>
                            </THead>
                            <TBody>
                                {members.data.members.map((m) => {
                                    const isSelf = m.id === me.user.id;
                                    const editable = canManage && !isSelf && (m.role !== 'owner' || workspace.role === 'owner');
                                    return (
                                        <TR key={m.id}>
                                            <TD>
                                                <p className="igr:font-medium">
                                                    {m.name} {isSelf ? <span className="igr:text-xs igr:font-normal igr:text-muted-foreground">({t('team.you')})</span> : null}
                                                </p>
                                                <p className="igr:text-xs igr:text-muted-foreground">{m.email}</p>
                                            </TD>
                                            <TD>
                                                {editable ? (
                                                    <Select
                                                        aria-label={t('team.role')}
                                                        className="igr:h-7 igr:w-40"
                                                        value={m.role}
                                                        onChange={(e) => changeRole.mutate({ id: m.id, role: e.target.value as WorkspaceRole })}
                                                    >
                                                        {(workspace.role === 'owner' ? ROLES : ROLES.filter((r) => r !== 'owner')).map((r) => (
                                                            <option key={r} value={r}>
                                                                {t(`roles.${r}`)}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                ) : (
                                                    <Badge variant="secondary">{t(`roles.${m.role}`)}</Badge>
                                                )}
                                            </TD>
                                            <TD>
                                                {m.two_factor_enabled ? (
                                                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-success">
                                                        <ShieldCheck className="igr:size-3.5" />
                                                        {t('security.on')}
                                                    </span>
                                                ) : (
                                                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                                                        <ShieldOff className="igr:size-3.5" />
                                                        {t('security.off')}
                                                    </span>
                                                )}
                                            </TD>
                                            <TD className="igr:text-muted-foreground">{formatRelative(m.last_login_at, workspace.locale)}</TD>
                                            <TD>
                                                {editable ? (
                                                    <Tooltip content={t('team.remove')}>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={t('team.remove')}
                                                            onClick={() => {
                                                                if (window.confirm(t('team.removeConfirm', { name: m.name }))) {
                                                                    remove.mutate(m.id);
                                                                }
                                                            }}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </Tooltip>
                                                ) : null}
                                            </TD>
                                        </TR>
                                    );
                                })}
                            </TBody>
                        </Table>
                    </Card>

                    {canManage ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('team.pending')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {members.data.invitations.length === 0 ? (
                                    <EmptyState icon={Users} title={t('team.noPending')} description={t('team.noPendingHelp')} className="igr:py-6" />
                                ) : (
                                    <ul className="igr:divide-y">
                                        {members.data.invitations.map((inv) => (
                                            <li key={inv.id} className="igr:flex igr:items-center igr:gap-3 igr:py-2.5 igr:text-[13px]">
                                                <span className="igr:flex-1">{inv.email}</span>
                                                <Badge variant="outline">{t(`roles.${inv.role}`)}</Badge>
                                                <span className="igr:text-xs igr:text-muted-foreground">{t('invitation.expires', { date: formatDate(inv.expires_at, workspace.locale) })}</span>
                                                <Button variant="ghost" size="sm" onClick={() => revoke.mutate(inv.id)}>
                                                    {t('team.revoke')}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            )}
            {canManage ? <InviteDialog open={inviteOpen} onOpenChange={setInviteOpen} /> : null}
        </>
    );
}
