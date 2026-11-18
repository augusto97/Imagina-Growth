import { Pause, Play } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLoyalty, useLoyaltyMutations } from './api';
import { ExplainerTab } from './ExplainerTab';
import { MembersTab } from './MembersTab';
import { OverviewTab } from './OverviewTab';
import { toProgramDraft, toProgramPayload } from './program';
import { RewardsTab } from './RewardsTab';
import { RulesTab } from './RulesTab';
import { SetupWizard } from './SetupWizard';
import { TiersTab } from './TiersTab';
import type { LoyaltyOverview, ProgramStatus } from './types';

const TABS = ['resumen', 'reglas', 'niveles', 'recompensas', 'miembros', 'pagina'] as const;
type Tab = (typeof TABS)[number];

/** Fidelización (modulos.md M13): puntos, niveles VIP, recompensas y saldo a favor. */
export function LoyaltyPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const loyalty = useLoyalty(workspace.id);

    if (loyalty.isError) {
        return (
            <>
                <PageHeader title={t('loyalty.title')} description={t('loyalty.description')} />
                <ErrorState error={loyalty.error} onRetry={() => void loyalty.refetch()} />
            </>
        );
    }
    if (loyalty.isPending) {
        return (
            <>
                <PageHeader title={t('loyalty.title')} description={t('loyalty.description')} />
                <Skeleton className="igr:mb-4 igr:h-10 igr:w-96" />
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="igr:h-24" />
                    ))}
                </div>
            </>
        );
    }
    if (loyalty.data.program.id === null) {
        return (
            <>
                <PageHeader title={t('loyalty.title')} description={t('loyalty.description')} />
                <SetupWizard data={loyalty.data} />
            </>
        );
    }
    return <Program data={loyalty.data} />;
}

function Program({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const canSeeMembers = useCan('viewAllContacts');
    const { saveProgram } = useLoyaltyMutations(workspace.id);
    const [params, setParams] = useSearchParams();
    const raw = params.get('tab');
    const tabs = TABS.filter((k) => k !== 'miembros' || canSeeMembers);
    const tab: Tab = (tabs as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'resumen';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'resumen') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };
    const status = data.program.status;
    const toggle = (next: ProgramStatus) =>
        saveProgram.mutate(toProgramPayload(toProgramDraft(data.program), next), {
            onSuccess: () => toast.success(t(next === 'active' ? 'loyalty.status.activated' : 'loyalty.status.pausedToast')),
            onError: (error) => toast.error(errorMessage(error, t)),
        });

    return (
        <>
            <PageHeader
                title={
                    <span className="igr:flex igr:items-center igr:gap-2">
                        {t('loyalty.title')}
                        <Badge variant={status === 'active' ? 'success' : status === 'paused' ? 'warning' : 'outline'}>{t(`loyalty.status.${status}`)}</Badge>
                    </span>
                }
                description={t('loyalty.programOf', { name: data.program.currency_name })}
                actions={
                    canManage ? (
                        status === 'active' ? (
                            <Button variant="outline" onClick={() => toggle('paused')} loading={saveProgram.isPending}>
                                <Pause />
                                {t('loyalty.status.pause')}
                            </Button>
                        ) : (
                            <Button onClick={() => toggle('active')} loading={saveProgram.isPending}>
                                <Play />
                                {t('loyalty.status.activate')}
                            </Button>
                        )
                    ) : null
                }
            />
            {status !== 'active' ? <p className="igr:mb-4 igr:rounded-md igr:border igr:bg-muted/40 igr:p-3 igr:text-[13px] igr:text-muted-foreground">{t(`loyalty.status.${status}Help`)}</p> : null}
            <Tabs value={tab} onValueChange={setTab}>
                <TabsList className="igr:overflow-x-auto">
                    {tabs.map((key) => (
                        <TabsTrigger key={key} value={key} className="igr:shrink-0">
                            {t(`loyalty.tabs.${key}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="resumen">
                    <OverviewTab data={data} />
                </TabsContent>
                <TabsContent value="reglas">
                    <RulesTab key={data.program.id ?? 'new'} data={data} />
                </TabsContent>
                <TabsContent value="niveles">
                    <TiersTab key={data.tiers.map((x) => x.id).join()} data={data} />
                </TabsContent>
                <TabsContent value="recompensas">
                    <RewardsTab data={data} />
                </TabsContent>
                {canSeeMembers ? (
                    <TabsContent value="miembros">
                        <MembersTab data={data} />
                    </TabsContent>
                ) : null}
                <TabsContent value="pagina">
                    <ExplainerTab data={data} />
                </TabsContent>
            </Tabs>
        </>
    );
}
