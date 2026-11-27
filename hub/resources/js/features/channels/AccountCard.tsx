import { Camera, CheckCircle2, Globe, Mail, MessageCircle, MessagesSquare, MoreHorizontal, Phone, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Progress } from '@/components/ui/progress';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ChannelAccount, UNOFFICIAL_PROVIDERS, useDisconnectChannel, useSyncChannel, useUpdateChannel } from './api';
import { CapabilityNotes } from './CapabilityNotes';
import { QualityBadge, StatusBadge } from './shared';

const ICONS = { email: Mail, whatsapp: MessageCircle, sms: Phone, messenger: MessagesSquare, instagram: Camera } as const;

export function AccountCard({ account, onTest, onDns }: { account: ChannelAccount; onTest: () => void; onDns: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    // TIER_1K → «1.000 clientes al día» (límite de conversaciones iniciadas por la empresa).
    const tierLabel = (tier: string) => {
        const m = /^TIER_(\d+)(K?)$/.exec(tier);
        if (!m) return tier === 'TIER_UNLIMITED' ? t('channels.tierUnlimited') : t('channels.tier', { tier });
        return t('channels.tier', { tier: formatNumber(Number(m[1]) * (m[2] ? 1000 : 1), workspace.locale) });
    };
    const canManage = useCan('manageWorkspace');
    const canMarketing = useCan('manageMarketing');
    const update = useUpdateChannel(workspace.id);
    const sync = useSyncChannel(workspace.id);
    const disconnect = useDisconnectChannel(workspace.id);
    const [confirm, setConfirm] = useState(false);
    const Icon = ICONS[account.channel];
    const fail = (error: unknown) => toast.error(t('channels.toast.updated'), isApiError(error) ? error.message : undefined);
    const warmup = account.warmup;

    return (
        <Card className="igr:flex igr:flex-col igr:gap-3 igr:p-4">
            <div className="igr:flex igr:items-start igr:gap-3">
                <span className="igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-muted">
                    <Icon className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                        <h3 className="igr:truncate igr:text-[13px] igr:font-semibold">{account.display_name}</h3>
                        <StatusBadge status={account.status} />
                        {account.is_default && (
                            <Badge variant="secondary">
                                <CheckCircle2 className="igr:size-3" aria-hidden="true" />
                                {t('channels.default')}
                            </Badge>
                        )}
                        {UNOFFICIAL_PROVIDERS.includes(account.provider) && (
                            <Badge variant="warning">
                                <ShieldAlert className="igr:size-3" aria-hidden="true" />
                                {t('channels.unofficial')}
                            </Badge>
                        )}
                    </div>
                    <p className="igr:mt-0.5 igr:truncate igr:text-xs igr:text-muted-foreground">
                        {t(`channels.provider.${account.provider}`)}
                        {account.identity ? ` · ${account.identity}` : ''}
                        {account.channel === 'email' && account.from_email ? ` · ${account.from_email}` : ''}
                    </p>
                </div>
                {(canManage || canMarketing) && account.status !== 'disconnected' && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={t('channels.actions.more')}>
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {account.status === 'active' && canMarketing && !account.webhook_subscription && <DropdownMenuItem onSelect={onTest}>{t('channels.actions.test')}</DropdownMenuItem>}
                            {account.channel === 'email' && account.provider === 'ses' && <DropdownMenuItem onSelect={onDns}>{t('channels.actions.verifyDns')}</DropdownMenuItem>}
                            {(account.webhook_subscription || account.provider === 'imagina_whats') && canMarketing && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        sync.mutate(account.id, {
                                            onSuccess: () => toast.success(t('channels.toast.checked')),
                                            onError: fail,
                                        })
                                    }
                                >
                                    {t('channels.actions.check')}
                                </DropdownMenuItem>
                            )}
                            {account.provider === 'meta_cloud' && canMarketing && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        sync.mutate(account.id, {
                                            onSuccess: (r) => toast.success(t('channels.toast.synced', { count: r.templates })),
                                            onError: fail,
                                        })
                                    }
                                >
                                    {t('channels.actions.sync')}
                                </DropdownMenuItem>
                            )}
                            {canManage && account.status === 'active' && !account.is_default && !UNOFFICIAL_PROVIDERS.includes(account.provider) && (
                                <DropdownMenuItem onSelect={() => update.mutate({ id: account.id, is_default: true }, { onSuccess: () => toast.success(t('channels.toast.updated')), onError: fail })}>
                                    {t('channels.actions.makeDefault')}
                                </DropdownMenuItem>
                            )}
                            {canManage && (account.status === 'active' || account.status === 'paused') && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        update.mutate({ id: account.id, status: account.status === 'active' ? 'paused' : 'active' }, { onSuccess: () => toast.success(t('channels.toast.updated')), onError: fail })
                                    }
                                >
                                    {account.status === 'active' ? t('channels.actions.pause') : t('channels.actions.resume')}
                                </DropdownMenuItem>
                            )}
                            {canManage && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="igr:text-destructive" onSelect={() => setConfirm(true)}>
                                        {t('channels.actions.disconnect')}
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
            <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                <QualityBadge account={account} />
                {account.messaging_limit_tier && <Badge variant="outline">{tierLabel(account.messaging_limit_tier)}</Badge>}
                {account.caps && <Badge variant="outline">{t('channels.caps', { hourly: account.caps.hourly, daily: account.caps.daily })}</Badge>}
                {account.domain && account.channel === 'email' && (
                    <Badge variant="outline">
                        <Globe className="igr:size-3" aria-hidden="true" />
                        {account.domain}
                    </Badge>
                )}
            </div>
            {account.channel === 'whatsapp' ? <CapabilityNotes caps={account.capabilities} /> : null}
            {warmup && (
                <div className="igr:space-y-1">
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {warmup.limit === null ? t('channels.warmupDone') : t('channels.warmup', { day: warmup.day, sent: formatNumber(warmup.sent_today), limit: formatNumber(warmup.limit) })}
                    </p>
                    {warmup.limit !== null && <Progress value={Math.min(100, (warmup.sent_today / Math.max(1, warmup.limit)) * 100)} label={t('channels.warmup', { day: warmup.day, sent: warmup.sent_today, limit: warmup.limit })} />}
                </div>
            )}
            {account.webhook_subscription && (
                <div className="igr:space-y-1 igr:rounded-md igr:bg-muted igr:p-2.5 igr:text-xs igr:text-muted-foreground">
                    <p>{t('channels.social.webhook', { object: account.webhook_subscription.object, url: account.webhook_url ?? '' })}</p>
                    <p className="igr:font-mono igr:text-[11px]">{account.webhook_subscription.fields.join(', ')}</p>
                    <p>{t(account.channel === 'messenger' ? 'channels.social.messenger.rules' : 'channels.social.instagram.rules')}</p>
                </div>
            )}
            {account.last_error && <p className="igr:rounded-md igr:bg-destructive/10 igr:p-2 igr:text-xs igr:text-destructive">{account.last_error}</p>}

            <Dialog open={confirm} onOpenChange={setConfirm}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('channels.disconnect.title', { name: account.display_name })}</DialogTitle>
                        <DialogDescription>{t('channels.disconnect.description')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirm(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={disconnect.isPending}
                            onClick={() =>
                                disconnect.mutate(account.id, {
                                    onSuccess: () => {
                                        setConfirm(false);
                                        toast.success(t('channels.toast.disconnected'));
                                    },
                                    onError: fail,
                                })
                            }
                        >
                            {t('channels.disconnect.confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
