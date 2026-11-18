import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AudiencesTab } from './audiences/AudiencesTab';
import { DeliveriesTab } from './DeliveriesTab';
import { DestinationsTab } from './DestinationsTab';
import { QualityTab } from './QualityTab';
import { SpendTab } from './SpendTab';

const TABS = ['destinos', 'entregas', 'calidad', 'audiencias', 'gasto'] as const;
type Tab = (typeof TABS)[number];

export function MeasurementPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const raw = params.get('tab');
    const tab: Tab = (TABS as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'destinos';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        p.set('tab', next);
        setParams(p, { replace: true });
    };

    return (
        <>
            <PageHeader title={t('measurement.title')} description={t('measurement.description')} />
            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    {TABS.map((key) => (
                        <TabsTrigger key={key} value={key}>
                            {t(`measurement.tabs.${key}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="destinos">
                    <DestinationsTab />
                </TabsContent>
                <TabsContent value="entregas">
                    <DeliveriesTab />
                </TabsContent>
                <TabsContent value="calidad">
                    <QualityTab />
                </TabsContent>
                <TabsContent value="audiencias">
                    <AudiencesTab />
                </TabsContent>
                <TabsContent value="gasto">
                    <SpendTab />
                </TabsContent>
            </Tabs>
        </>
    );
}
