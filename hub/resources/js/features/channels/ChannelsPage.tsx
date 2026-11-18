import { FileText, Mail, MessageCircle, Phone, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { AccountCard } from './AccountCard';
import { type ChannelAccount, type ChannelKind, useChannels } from './api';
import { EmailDialog } from './dialogs/EmailDialog';
import { TestSendDialog } from './dialogs/TestSendDialog';
import { TwilioDialog } from './dialogs/TwilioDialog';
import { WhatsAppDialog } from './dialogs/WhatsAppDialog';
import { ZenderDialog } from './dialogs/ZenderDialog';
import { SuppressionsTab } from './SuppressionsTab';

const TABS = ['email', 'whatsapp', 'sms', 'suppressions'] as const;
type Tab = (typeof TABS)[number];
const ICONS = { email: Mail, whatsapp: MessageCircle, sms: Phone } as const;

/** Configuración → Canales (M07). */
export function ChannelsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const canMarketing = useCan('manageMarketing');
    const channels = useChannels(workspace.id);
    const [params, setParams] = useSearchParams();
    const tab: Tab = TABS.find((x) => x === params.get('tab')) ?? 'email';
    const [dialog, setDialog] = useState<'email' | 'whatsapp' | 'zender' | 'sms' | null>(null);
    const [dnsFor, setDnsFor] = useState<ChannelAccount | null>(null);
    const [testFor, setTestFor] = useState<ChannelAccount | null>(null);

    const accounts = (kind: ChannelKind) => (channels.data?.data ?? []).filter((a) => a.channel === kind);
    const connect = (kind: ChannelKind) => (kind === 'email' ? 'email' : kind === 'whatsapp' ? 'whatsapp' : 'sms');

    return (
        <div>
            <PageHeader title={t('channels.title')} description={t('channels.description')} />
            <Tabs value={tab} onValueChange={(v) => setParams((p) => { p.set('tab', v); return p; }, { replace: true })}>
                <TabsList>
                    {TABS.map((x) => (
                        <TabsTrigger key={x} value={x}>
                            {t(`channels.tabs.${x}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {(['email', 'whatsapp', 'sms'] as const).map((kind) => {
                    const list = accounts(kind);
                    const Icon = ICONS[kind];
                    return (
                        <TabsContent key={kind} value={kind} className="igr:mt-4 igr:space-y-3">
                            <div className="igr:flex igr:flex-wrap igr:justify-end igr:gap-2">
                                {kind === 'whatsapp' && canMarketing && (
                                    <Button variant="outline" asChild>
                                        <Link to={`/w/${workspace.id}/canales/plantillas-whatsapp`}>
                                            <FileText />
                                            {t('channels.actions.templates')}
                                        </Link>
                                    </Button>
                                )}
                                {canManage && list.length > 0 && (
                                    <Button onClick={() => setDialog(connect(kind))}>
                                        <Plus />
                                        {t(kind === 'email' ? 'channels.actions.connectEmail' : kind === 'whatsapp' ? 'channels.actions.connectWhatsApp' : 'channels.actions.connectSms')}
                                    </Button>
                                )}
                            </div>
                            {channels.isError ? (
                                <ErrorState error={channels.error} onRetry={() => void channels.refetch()} />
                            ) : channels.isPending ? (
                                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                                    <Skeleton className="igr:h-32" />
                                    <Skeleton className="igr:h-32" />
                                </div>
                            ) : list.length === 0 ? (
                                <EmptyState
                                    icon={Icon}
                                    title={t(`channels.empty.${kind}.title`)}
                                    description={t(`channels.empty.${kind}.description`)}
                                    action={
                                        canManage ? (
                                            <Button onClick={() => setDialog(connect(kind))}>
                                                <Plus />
                                                {t(kind === 'email' ? 'channels.actions.connectEmail' : kind === 'whatsapp' ? 'channels.actions.connectWhatsApp' : 'channels.actions.connectSms')}
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                                    {list.map((a) => (
                                        <AccountCard key={a.id} account={a} onTest={() => setTestFor(a)} onDns={() => setDnsFor(a)} />
                                    ))}
                                </div>
                            )}
                        </TabsContent>
                    );
                })}
                <TabsContent value="suppressions" className="igr:mt-4">
                    {canMarketing ? <SuppressionsTab /> : <p className="igr:text-[13px] igr:text-muted-foreground">{t('channels.suppressions.description')}</p>}
                </TabsContent>
            </Tabs>

            <EmailDialog
                open={dialog === 'email' || dnsFor !== null}
                dnsFor={dnsFor}
                onClose={() => {
                    setDialog(null);
                    setDnsFor(null);
                }}
            />
            <WhatsAppDialog open={dialog === 'whatsapp'} meta={channels.data?.meta} onClose={() => setDialog(null)} onZender={() => setDialog('zender')} />
            <ZenderDialog open={dialog === 'zender'} caps={channels.data?.meta.zender_caps ?? { hourly: 30, daily: 200 }} onClose={() => setDialog(null)} />
            <TwilioDialog open={dialog === 'sms'} onClose={() => setDialog(null)} />
            <TestSendDialog account={testFor} onClose={() => setTestFor(null)} />
        </div>
    );
}
