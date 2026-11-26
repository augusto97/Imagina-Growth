import { useTranslation } from 'react-i18next';
import { Navigate, useSearchParams } from 'react-router';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan } from '@/hooks/useWorkspace';
import { BrandTab } from './BrandTab';
import { ClientsTab } from './ClientsTab';
import { ReportsTab } from './ReportsTab';
import { TemplatesTab } from './TemplatesTab';

const TABS = ['clientes', 'plantillas', 'reportes', 'marca'] as const;
type Tab = (typeof TABS)[number];
const LABEL: Record<Tab, string> = { clientes: 'clients', plantillas: 'templates', reportes: 'reports', marca: 'brand' };

/* Panel de agencia (M22): clientes, plantillas maestras, reportes mensuales y marca blanca. */
export function AgencyPage() {
    const { t } = useTranslation();
    const allowed = useCan('viewAgency');
    const [params, setParams] = useSearchParams();
    const raw = params.get('tab');
    const tab: Tab = TABS.includes(raw as Tab) ? (raw as Tab) : 'clientes';
    if (!allowed) {
        return <Navigate to=".." replace />;
    }
    return (
        <div>
            <PageHeader title={t('agency.title')} description={t('agency.description')} />
            <Tabs value={tab} onValueChange={(v) => setParams(v === 'clientes' ? {} : { tab: v }, { replace: true })}>
                <TabsList>
                    {TABS.map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`agency.tabs.${LABEL[k]}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="clientes">
                    <ClientsTab />
                </TabsContent>
                <TabsContent value="plantillas">
                    <TemplatesTab />
                </TabsContent>
                <TabsContent value="reportes">
                    <ReportsTab />
                </TabsContent>
                <TabsContent value="marca">
                    <BrandTab />
                </TabsContent>
            </Tabs>
        </div>
    );
}
