import { useMutation, useQueryClient } from '@tanstack/react-query';
import { MailPlus, ShieldCheck, ShieldOff, Trash2, UserPlus } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { keys, useMembers, wsPath } from '@/api/queries';
import type { WorkspaceRole } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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

/** Iniciales para el avatar («Dueño demo» → «DD»). */
function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/).filter((w) => w !== '');
    return ((words[0]?.[0] ?? '?') + (words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

/** Qué puede hacer cada rol, en palabras (para elegir bien antes de invitar). */
function RolesCard() {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('teamPage.rolesTitle')}</CardTitle>
                <CardDescription>{t('teamPage.rolesDescription')}</CardDescription>
            </CardHeader>
            <CardContent>
                <dl className="igr:grid igr:gap-x-6 igr:gap-y-3 igr:sm:grid-cols-2">
                    {ROLES.map((r) => (
                        <div key={r} className="igr:flex igr:gap-3">
                            <dt className="igr:w-28 igr:shrink-0">
                                <Badge variant={r === 'owner' ? 'brand' : 'secondary'}>{t(`roles.${r}`)}</Badge>
                            </dt>
                            <dd className="igr:text-support igr:text-muted-foreground">{t(`roleHelp.${r}`)}</dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
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
    const [removing, setRemoving] = useState<{ id: string; name: string } | null>(null);

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
                                                <div className="igr:flex igr:items-center igr:gap-3">
                                                    <span aria-hidden className="igr:flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-xs igr:font-semibold igr:text-brand-soft-foreground">
                                                        {initialsOf(m.name)}
                                                    </span>
                                                    <div className="igr:min-w-0">
                                                        <p className="igr:font-medium">
                                                            {m.name} {isSelf ? <span className="igr:text-xs igr:font-normal igr:text-muted-foreground">({t('team.you')})</span> : null}
                                                        </p>
                                                        <p className="igr:text-xs igr:text-muted-foreground">{m.email}</p>
                                                    </div>
                                                </div>
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
                                                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-warning">
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
                                                            onClick={() => setRemoving({ id: m.id, name: m.name })}
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

                    {canManage && members.data.invitations.length > 0 ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('team.pending')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="igr:divide-y">
                                    {members.data.invitations.map((inv) => (
                                        <li key={inv.id} className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-1 igr:py-2.5 igr:text-[13px]">
                                            <span className="igr:min-w-0 igr:flex-1 igr:truncate">{inv.email}</span>
                                            <Badge variant="outline">{t(`roles.${inv.role}`)}</Badge>
                                            <span className="igr:text-xs igr:text-muted-foreground">{t('invitation.expires', { date: formatDate(inv.expires_at, workspace.locale, workspace.timezone) })}</span>
                                            <Button variant="ghost" size="sm" loading={revoke.isPending} onClick={() => revoke.mutate(inv.id)}>
                                                {t('team.revoke')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    ) : null}
                    {canManage && members.data.members.length === 1 && members.data.invitations.length === 0 ? (
                        <EmptyState
                            illustration="customers"
                            size="compact"
                            title={t('teamPage.aloneTitle')}
                            description={t('teamPage.aloneDescription')}
                            action={
                                <Button onClick={() => setInviteOpen(true)}>
                                    <UserPlus />
                                    {t('team.invite')}
                                </Button>
                            }
                        />
                    ) : null}
                    <RolesCard />
                </div>
            )}
            <Dialog open={removing !== null} onOpenChange={(o) => !o && setRemoving(null)}>
                <DialogContent className="igr:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('team.removeConfirm', { name: removing?.name ?? '' })}</DialogTitle>
                        <DialogDescription>{t('teamPage.removeHelp')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRemoving(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={remove.isPending}
                            onClick={() => removing && remove.mutate(removing.id, { onSettled: () => setRemoving(null) })}
                        >
                            {t('team.remove')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {canManage ? <InviteDialog open={inviteOpen} onOpenChange={setInviteOpen} /> : null}
        </>
    );
}
