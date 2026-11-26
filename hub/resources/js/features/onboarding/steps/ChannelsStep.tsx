import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight, CircleCheck, Mail, MessageCircle, TriangleAlert, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { keys } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { useChannels } from '@/features/channels/api';
import { EmailDialog } from '@/features/channels/dialogs/EmailDialog';
import { WhatsAppOneClick } from '@/features/connections/WhatsAppOneClick';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { onboardingKeys } from '../api';
import { speaksServices, stepOf } from '../flow';
import { StepFrame } from '../StepFrame';
import type { Onboarding } from '../types';

/*
 * Paso 4 (docs/diseno/rediseno-v2.md §4.2): WhatsApp con un clic (el mismo «Conectar WhatsApp» de Conexiones) y el
 * correo, cada uno con lo que permite. Se puede dejar para después: las estrategias que los necesitan lo dicen.
 */

function ChannelCard({
    icon,
    tone,
    title,
    benefits,
    state,
    badge,
    children,
}: {
    icon: LucideIcon;
    tone: IconTone;
    title: string;
    benefits: string[];
    state: 'active' | 'down' | null;
    badge?: ReactNode;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    return (
        <article className="igr:flex igr:min-w-0 igr:flex-col igr:rounded-2xl igr:border igr:bg-card igr:p-5">
            <div className="igr:flex igr:items-center igr:gap-3">
                <IconTile icon={icon} tone={tone} />
                <h2 className="igr:min-w-0 igr:flex-1 igr:text-body igr:font-semibold igr:text-foreground">{title}</h2>
                {badge}
            </div>
            <ul className="igr:mt-3 igr:flex-1 igr:space-y-1.5">
                {benefits.map((b) => (
                    <li key={b} className="igr:flex igr:gap-2 igr:text-support igr:text-muted-foreground">
                        <span aria-hidden className="igr:mt-2 igr:size-1.5 igr:shrink-0 igr:rounded-full igr:bg-primary" />
                        <span className="igr:min-w-0">{b}</span>
                    </li>
                ))}
            </ul>
            <div className="igr:mt-4 igr:border-t igr:pt-4">
                {state === 'active' ? (
                    <p className="igr:flex igr:items-center igr:gap-2 igr:text-support igr:font-medium igr:text-success" role="status">
                        <CircleCheck className="igr:size-4" aria-hidden />
                        {t('onboarding.wizard.channels.connected')}
                    </p>
                ) : (
                    <>
                        {state === 'down' ? (
                            <p className="igr:mb-3 igr:flex igr:items-center igr:gap-2 igr:text-support igr:text-warning">
                                <TriangleAlert className="igr:size-4" aria-hidden />
                                {t('onboarding.wizard.channels.down')}
                            </p>
                        ) : null}
                        {children}
                    </>
                )}
            </div>
        </article>
    );
}

export function ChannelsStep({ state, base, onBack, onNext, busy }: { state: Onboarding; base: string; onBack: () => void; onNext: () => void; busy: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const channels = useChannels(workspace.id);
    const [emailOpen, setEmailOpen] = useState(false);
    const services = speaksServices(state.business_type ?? workspace.business_type);
    const step = stepOf(state, 'channels');
    const wa = step?.channels.whatsapp ?? null;
    const email = step?.channels.email ?? null;
    const canManage = workspace.abilities.includes('manageWorkspace');
    const signupReady = channels.data?.meta.embedded_signup.configured ?? false;
    const list = (key: string) => {
        const v = t(key, { returnObjects: true }) as unknown;
        return Array.isArray(v) ? (v as string[]) : [];
    };
    const refresh = () => {
        void qc.invalidateQueries({ queryKey: onboardingKeys.state(workspace.id) });
        void qc.invalidateQueries({ queryKey: keys.me });
    };

    return (
        <StepFrame
            step="channels"
            title={t('onboarding.wizard.channels.title')}
            description={t(services ? 'onboarding.wizard.channels.descriptionServices' : 'onboarding.wizard.channels.description')}
            onBack={onBack}
            onNext={onNext}
            nextLabel={wa === 'active' || email === 'active' ? t('onboarding.wizard.next') : t('onboarding.wizard.channels.later')}
            busy={busy}
            footerNote={wa === 'active' || email === 'active' ? undefined : t('onboarding.wizard.channels.footer')}
        >
            <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                <ChannelCard
                    icon={MessageCircle}
                    tone="emerald"
                    title={t('onboarding.wizard.channels.whatsapp')}
                    benefits={list(services ? 'onboarding.wizard.channels.whatsappBenefitsServices' : 'onboarding.wizard.channels.whatsappBenefits')}
                    state={wa}
                    badge={<Badge variant="brand">{t('onboarding.wizard.channels.recommended')}</Badge>}
                >
                    {!canManage ? (
                        <p className="igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.channels.ask')}</p>
                    ) : signupReady ? (
                        <WhatsAppOneClick
                            configured
                            onConnected={() => {
                                toast.success(t('onboarding.wizard.channels.toastWhatsapp'));
                                refresh();
                            }}
                        />
                    ) : (
                        <Button asChild variant="outline" size="sm">
                            <Link to={`${base}/canales`}>
                                {t('onboarding.wizard.channels.whatsappManual')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    )}
                </ChannelCard>
                <ChannelCard
                    icon={Mail}
                    tone="sky"
                    title={t('onboarding.wizard.channels.email')}
                    benefits={list(services ? 'onboarding.wizard.channels.emailBenefitsServices' : 'onboarding.wizard.channels.emailBenefits')}
                    state={email}
                >
                    {canManage ? (
                        <Button variant="outline" size="sm" onClick={() => setEmailOpen(true)}>
                            <Mail aria-hidden />
                            {t('onboarding.wizard.channels.emailConnect')}
                        </Button>
                    ) : (
                        <p className="igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.channels.ask')}</p>
                    )}
                </ChannelCard>
            </div>
            {canManage ? (
                <EmailDialog
                    open={emailOpen}
                    onClose={() => {
                        setEmailOpen(false);
                        refresh();
                    }}
                    dnsFor={null}
                />
            ) : null}
        </StepFrame>
    );
}
