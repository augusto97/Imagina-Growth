import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { AffiliatesTab } from './AffiliatesTab';
import { useReferrals } from './api';
import { ConversionsTab } from './ConversionsTab';
import { ProgramTab } from './ProgramTab';
import { ReferralsTab } from './ReferralsTab';
import { ReportTab } from './ReportTab';

const TABS = ['resumen', 'referidos', 'afiliados', 'comisiones', 'programa'] as const;
type Tab = (typeof TABS)[number];

export function ReferralsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [params, setParams] = useSearchParams();
    const raw = params.get('tab');
    const tab: Tab = (TABS as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'resumen';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        p.set('tab', next);
        setParams(p, { replace: true });
    };
    const pending = useReferrals(workspace.id, { status: '', review: true, page: 1 }).data?.pending_review ?? 0;

    return (
        <>
            <PageHeader title={t('referrals.title')} description={t('referrals.description')} />
            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    {TABS.map((key) => (
                        <TabsTrigger key={key} value={key}>
                            {t(`referrals.tabs.${key}`)}
                            {key === 'referidos' && pending > 0 ? (
                                <Badge variant="warning" className="igr:ml-1.5" aria-label={t('referrals.review.count', { count: pending })}>
                                    {pending}
                                </Badge>
                            ) : null}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="resumen">
                    <ReportTab onConfigure={() => setTab('programa')} />
                </TabsContent>
                <TabsContent value="referidos">
                    <ReferralsTab />
                </TabsContent>
                <TabsContent value="afiliados">
                    <AffiliatesTab />
                </TabsContent>
                <TabsContent value="comisiones">
                    <ConversionsTab />
                </TabsContent>
                <TabsContent value="programa">
                    <ProgramTab />
                </TabsContent>
            </Tabs>
        </>
    );
}
