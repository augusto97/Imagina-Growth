import { Copy, UserPlus } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type PlatformAdmin, useAddAdmin, usePlatformAdmins, useRemoveAdmin } from './settings-api';

/** Plataforma → Ajustes → Administradores (F17): quién administra la plataforma, sin consola (antes `igr:admin:create`). */
export function AdminsPanel() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const admins = usePlatformAdmins();
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<PlatformAdmin | null>(null);

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-col igr:items-start igr:justify-between igr:gap-3 igr:sm:flex-row">
                <div className="igr:min-w-0 igr:flex-1 igr:space-y-1">
                    <CardTitle>{t('platformSettings.sections.admins.title')}</CardTitle>
                    <CardDescription>{t('platformSettings.sections.admins.description')}</CardDescription>
                </div>
                <Button onClick={() => setAdding(true)}>
                    <UserPlus />
                    {t('platformSettings.admins.add')}
                </Button>
            </CardHeader>
            <CardContent>
                {admins.isError ? (
                    <ErrorState error={admins.error} onRetry={() => void admins.refetch()} />
                ) : admins.isPending ? (
                    <Skeleton className="igr:h-40" />
                ) : (
                    <div className="igr:overflow-x-auto">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platformSettings.admins.person')}</TH>
                                    <TH>{t('platformSettings.admins.twoFactor')}</TH>
                                    <TH className="igr:hidden igr:md:table-cell">{t('platformSettings.admins.lastLogin')}</TH>
                                    <TH>
                                        <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                                    </TH>
                                </TR>
                            </THead>
                            <TBody>
                                {admins.data.data.map((a) => (
                                    <TR key={a.id}>
                                        <TD>
                                            <p className="igr:font-medium">
                                                {a.name}
                                                {a.is_me ? (
                                                    <Badge variant="secondary" className="igr:ml-2">
                                                        {t('platformSettings.admins.me')}
                                                    </Badge>
                                                ) : null}
                                            </p>
                                            <p className="igr:break-all igr:text-xs igr:text-muted-foreground">{a.email}</p>
                                        </TD>
                                        <TD>
                                            <Badge variant={a.two_factor ? 'success' : 'warning'}>
                                                {t(a.two_factor ? 'platformSettings.admins.twoFactorOn' : 'platformSettings.admins.twoFactorOff')}
                                            </Badge>
                                        </TD>
                                        <TD className="igr:hidden igr:whitespace-nowrap igr:text-muted-foreground igr:md:table-cell">
                                            {a.last_login_at ? formatDateTime(a.last_login_at, workspace.locale) : t('platformSettings.admins.never')}
                                        </TD>
                                        <TD className="igr:text-right">
                                            {!a.is_me ? (
                                                <Button size="sm" variant="ghost" onClick={() => setRemoving(a)}>
                                                    {t('platformSettings.admins.remove')}
                                                </Button>
                                            ) : null}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
            </CardContent>
            {adding ? <AddAdminDialog onClose={() => setAdding(false)} /> : null}
            {removing ? <RemoveAdminDialog admin={removing} onClose={() => setRemoving(null)} /> : null}
        </Card>
    );
}

function AddAdminDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const add = useAddAdmin();
    const [email, setEmail] = useState('');
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [created, setCreated] = useState<{ email: string; password: string } | null>(null);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        add.mutate(
            { email, name: name || undefined, password },
            {
                onSuccess: (r) => {
                    if (r.created && r.password) {
                        setCreated({ email: r.admin.email, password: r.password });
                        toast.success(t('platformSettings.admins.created', { email: r.admin.email }));
                        return;
                    }
                    toast.success(t('platformSettings.admins.promoted', { email: r.admin.email }));
                    onClose();
                },
                onError: (err) => setErrors(isApiError(err) ? { ...err.fieldErrors, form: err.message } : { form: t('errors.title') }),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                {created ? (
                    <div>
                        <DialogHeader>
                            <DialogTitle>{t('platformSettings.admins.created', { email: created.email })}</DialogTitle>
                            <DialogDescription>{t('platformSettings.admins.passwordHelp')}</DialogDescription>
                        </DialogHeader>
                        <div className="igr:space-y-2 igr:py-4">
                            <p className="igr:text-[13px]">{t('platformSettings.admins.password')}</p>
                            <div className="igr:flex igr:items-center igr:gap-2">
                                <code className="igr:flex-1 igr:break-all igr:rounded igr:bg-muted igr:px-2 igr:py-1.5 igr:font-mono igr:text-sm">{created.password}</code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={t('platformSettings.copy')}
                                    onClick={() => void navigator.clipboard.writeText(created.password).then(() => toast.success(t('platformSettings.copied')))}
                                >
                                    <Copy />
                                </Button>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button onClick={onClose}>{t('common.close')}</Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>{t('platformSettings.admins.addTitle')}</DialogTitle>
                            <DialogDescription>{t('platformSettings.admins.addHelp')}</DialogDescription>
                        </DialogHeader>
                        <div className="igr:grid igr:gap-4 igr:py-4">
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="adm-email">{t('platformSettings.admins.email')}</Label>
                                <Input id="adm-email" type="email" required autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
                                <FieldError message={errors.email} />
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="adm-name">{t('platformSettings.admins.name')}</Label>
                                <Input id="adm-name" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} />
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="adm-password">{t('platformSettings.admins.yourPassword')}</Label>
                                <Input id="adm-password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                                <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.admins.yourPasswordHelp')}</p>
                                <FieldError message={errors.password} />
                            </div>
                            {errors.form && !errors.email && !errors.password ? <p className="igr:text-sm igr:text-destructive">{errors.form}</p> : null}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" loading={add.isPending}>
                                {t('platformSettings.admins.add')}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function RemoveAdminDialog({ admin, onClose }: { admin: PlatformAdmin; onClose: () => void }) {
    const { t } = useTranslation();
    const remove = useRemoveAdmin();
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        remove.mutate(
            { id: admin.id, password },
            {
                onSuccess: () => {
                    toast.success(t('platformSettings.admins.removed'));
                    onClose();
                },
                onError: (err) => setError(isApiError(err) ? err.message : t('errors.title')),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t('platformSettings.admins.removeTitle', { name: admin.name })}</DialogTitle>
                        <DialogDescription>{t('platformSettings.admins.removeHelp')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:gap-2 igr:py-4">
                        <Label htmlFor="adm-remove-password">{t('platformSettings.admins.yourPassword')}</Label>
                        <Input id="adm-remove-password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                        {error ? <p className="igr:text-sm igr:text-destructive">{error}</p> : null}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant="destructive" loading={remove.isPending}>
                            {t('platformSettings.admins.remove')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
