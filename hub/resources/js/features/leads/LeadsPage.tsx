import { ListTodo } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { useLeadSettings, useTeam } from './api';
import { Board } from './Board';
import { KpisTab } from './KpisTab';
import { LeadDrawer } from './LeadDrawer';
import { LeadList } from './LeadList';
import { QualificationTab } from './QualificationTab';
import { SettingsTab } from './SettingsTab';
import type { LeadFilters, Temperature } from './types';

const TABS = ['tablero', 'lista', 'indicadores', 'calificacion', 'ajustes'] as const;
type Tab = (typeof TABS)[number];

export const CHANNELS = ['paid_google', 'paid_meta', 'paid_tiktok', 'ctwa', 'organic_search', 'organic_social', 'whatsapp', 'email', 'referral', 'direct', 'manual'];

/** Leads (modulos.md M18): tablero por etapas, lista, indicadores y ajustes. `?lead=` abre el detalle. */
export function LeadsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageServices');
    const [params, setParams] = useSearchParams();
    const tabs = TABS.filter((k) => canManage || (k !== 'ajustes' && k !== 'calificacion'));
    const raw = params.get('tab');
    const tab: Tab = (tabs as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'tablero';
    const set = (key: string, value: string | null) => {
        const p = new URLSearchParams(params);
        if (value === null || value === '') p.delete(key);
        else p.set(key, value);
        if (key !== 'lead' && key !== 'page') p.delete('page');
        setParams(p, { replace: key !== 'lead' });
    };
    const filters: LeadFilters = {
        pipeline: params.get('pipeline') ?? '',
        owner: params.get('owner') ?? '',
        temperature: (params.get('temperature') ?? '') as Temperature | '',
        source: params.get('source') ?? '',
        service: params.get('service') ?? '',
    };

    return (
        <>
            <PageHeader
                title={t('leads.title')}
                description={t('leads.description')}
                actions={
                    <Button asChild>
                        <Link to={`/w/${workspace.id}/hoy`}>
                            <ListTodo aria-hidden />
                            {t('leads.today')}
                        </Link>
                    </Button>
                }
            />
            <Tabs value={tab} onValueChange={(v) => set('tab', v === 'tablero' ? null : v)}>
                <TabsList>
                    {tabs.map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`leads.tabs.${k}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {tab === 'tablero' || tab === 'lista' || tab === 'indicadores' ? <FilterBar filters={filters} onChange={set} compact={tab === 'indicadores'} /> : null}
                <TabsContent value="tablero" className="igr:mt-3">
                    <Board
                        filters={filters}
                        onOpen={(id) => set('lead', id)}
                        onSeeAll={(stage) => {
                            const p = new URLSearchParams(params);
                            p.set('tab', 'lista');
                            p.set('stage', stage);
                            p.delete('page');
                            setParams(p, { replace: true });
                        }}
                    />
                </TabsContent>
                <TabsContent value="lista" className="igr:mt-3">
                    <LeadList
                        filters={{
                            ...filters,
                            stage: params.get('stage') ?? '',
                            status: (params.get('status') ?? '') as LeadFilters['status'],
                            sort: (params.get('sort') ?? 'recent') as LeadFilters['sort'],
                            page: Number(params.get('page') ?? 1),
                        }}
                        onOpen={(id) => set('lead', id)}
                        onChange={set}
                    />
                </TabsContent>
                <TabsContent value="indicadores" className="igr:mt-3">
                    <KpisTab pipeline={filters.pipeline ?? ''} />
                </TabsContent>
                {canManage ? (
                    <TabsContent value="calificacion" className="igr:mt-4">
                        <QualificationTab />
                    </TabsContent>
                ) : null}
                {canManage ? (
                    <TabsContent value="ajustes" className="igr:mt-4">
                        <SettingsTab />
                    </TabsContent>
                ) : null}
            </Tabs>
            <LeadDrawer id={params.get('lead')} onClose={() => set('lead', null)} />
        </>
    );
}

function FilterBar({ filters, onChange, compact }: { filters: LeadFilters; onChange: (key: string, value: string | null) => void; compact: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const seeAll = useCan('viewAllContacts');
    const team = useTeam(workspace.id);
    const settings = useLeadSettings(workspace.id);
    const pipelines = settings.data?.pipelines ?? [];
    const label = 'igr:sr-only';
    if (compact && pipelines.length <= 1) return null;

    return (
        <div className="igr:mt-4 igr:grid igr:grid-cols-2 igr:gap-2 igr:sm:flex igr:sm:flex-wrap igr:sm:items-center" role="search" aria-label={t('leads.filters.label')}>
            {pipelines.length > 1 ? (
                <label>
                    <span className={label}>{t('leads.filters.pipeline')}</span>
                    <Select className="igr:h-8 igr:w-full igr:sm:w-52" value={filters.pipeline ?? ''} onChange={(e) => onChange('pipeline', e.target.value)}>
                        {pipelines.map((p) => (
                            <option key={p.id} value={p.is_default ? '' : p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                </label>
            ) : null}
            {compact ? null : (
                <>
                    {seeAll ? (
                        <label>
                            <span className={label}>{t('leads.filters.owner')}</span>
                            <Select className="igr:h-8 igr:w-full igr:sm:w-52" value={filters.owner ?? ''} onChange={(e) => onChange('owner', e.target.value)}>
                                <option value="">{t('leads.filters.anyOwner')}</option>
                                <option value="me">{t('leads.filters.mine')}</option>
                                <option value="none">{t('leads.filters.unassigned')}</option>
                                {(team.data?.data ?? []).map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    ) : null}
                    <label>
                        <span className={label}>{t('leads.filters.temperature')}</span>
                        <Select className="igr:h-8 igr:w-full igr:sm:w-52" value={filters.temperature ?? ''} onChange={(e) => onChange('temperature', e.target.value)}>
                            <option value="">{t('leads.filters.anyTemperature')}</option>
                            {(['hot', 'warm', 'cold'] as const).map((k) => (
                                <option key={k} value={k}>
                                    {t(`leads.temperature.${k}`)}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <label>
                        <span className={label}>{t('leads.filters.source')}</span>
                        <Select className="igr:h-8 igr:w-full igr:sm:w-52" value={filters.source ?? ''} onChange={(e) => onChange('source', e.target.value)}>
                            <option value="">{t('leads.filters.anySource')}</option>
                            {CHANNELS.map((c) => (
                                <option key={c} value={c}>
                                    {t(`leads.channels.${c}`)}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <label>
                        <span className={label}>{t('leads.filters.service')}</span>
                        <Input
                            className="igr:h-8 igr:w-full igr:sm:w-52"
                            placeholder={t('leads.filters.servicePlaceholder')}
                            defaultValue={filters.service ?? ''}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') onChange('service', e.currentTarget.value.trim());
                            }}
                            onBlur={(e) => onChange('service', e.currentTarget.value.trim())}
                        />
                    </label>
                </>
            )}
        </div>
    );
}
