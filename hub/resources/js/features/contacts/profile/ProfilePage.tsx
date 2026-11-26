import { CalendarPlus, FileDown, GitMerge, MessageCircle, MoreHorizontal, Plus, Trash2, UserX, UsersRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { AutoBreadcrumbs } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DESKTOP_QUERY, useMedia } from '@/hooks/useMedia';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { ConfirmDeleteDialog } from '@/features/privacy/ConfirmDeleteDialog';
import { ExportDialog } from '@/features/privacy/ExportDialog';
import { useRunDataRequest } from '@/features/privacy/api';
import { ProfileSummaryCard } from '@/features/intelligence/ProfileSummaryCard';
import { ContactLeadCard, useCreateContactLead } from '@/features/leads/ContactLeadCard';
import { ContactLoyaltyTab } from '@/features/loyalty/ContactLoyaltyTab';
import { useQuery } from '@tanstack/react-query';
import { segmentsQuery } from '@/features/segments/api';
import { useContactAbilities } from '../abilities';
import { useContactProfile, useContactServices } from '../api';
import { useBusiness } from '../business';
import { errorMessage } from '../errors';
import { timelineGroupsFor } from '../labels';
import { AddToGroupDialog } from '../list/AddToGroupDialog';
import { DataTab } from './DataTab';
import { IdentitiesTab } from './IdentitiesTab';
import { currentLead, whatsappDigits } from './insights';
import { MessagesTab } from './MessagesTab';
import { MergeDialog } from './MergeDialog';
import { NotesPanel } from './NotesPanel';
import { OrdersTab } from './OrdersTab';
import { PrivacyTab } from './PrivacyTab';
import { ProductsTab } from './ProductsTab';
import { ProfileHeader } from './ProfileHeader';
import { ServicesTab } from './ServicesTab';
import { SummaryTab } from './SummaryTab';
import { Timeline } from './Timeline';

const ALL_TABS = ['resumen', 'citas', 'pedidos', 'productos', 'mensajes', 'fidelizacion', 'actividad', 'notas', 'datos', 'identidades', 'privacidad'] as const;
type Tab = (typeof ALL_TABS)[number];

/**
 * Las pestañas que aplican: pedidos y productos solo con tienda; citas y cotizaciones con servicios; puntos con el módulo
 * de fidelización; y en el celular y la tableta, la actividad y las notas (que en el escritorio van al lado).
 */
export function profileTabs(opts: { store: boolean; services: boolean; loyalty: boolean; aside: boolean }): Tab[] {
    return ALL_TABS.filter((tab) => {
        switch (tab) {
            case 'citas':
                return opts.services;
            case 'pedidos':
            case 'productos':
                return opts.store;
            case 'fidelizacion':
                return opts.store && opts.loyalty;
            case 'actividad':
            case 'notas':
                return !opts.aside;
            default:
                return true;
        }
    });
}

/** Perfil 360 (modulos.md M03). */
export function ProfilePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { contactId = '' } = useParams();
    const navigate = useNavigate();
    const can = useContactAbilities();
    const canLeads = useCan('useLeads');
    const canMarket = useCan('manageMarketing');
    const canSeeAll = useCan('viewAllContacts');
    const business = useBusiness();
    const desktop = useMedia(DESKTOP_QUERY, true);
    const [params, setParams] = useSearchParams();
    const profile = useContactProfile(workspace.id, contactId);
    const services = useContactServices(workspace.id, contactId, business.services && canLeads);
    const segments = useQuery({ ...segmentsQuery(workspace.id), enabled: canMarket && canSeeAll });
    const createLead = useCreateContactLead(contactId);
    const [dialog, setDialog] = useState<'merge' | 'export' | 'delete' | 'group' | null>(null);
    const remove = useRunDataRequest(workspace.id, contactId);
    const modules = workspace.modules ?? null;
    const tabs = profileTabs({ store: business.store, services: business.services && canLeads, loyalty: modules === null || modules.includes('loyalty'), aside: desktop });
    const tabParam = params.get('tab');
    const tab: Tab = tabs.find((x) => x === tabParam) ?? 'resumen';
    // Un contacto fusionado en otro: se abre el que quedó (el enlace viejo sigue sirviendo).
    const survivor = profile.data?.merged_into ?? null;
    useEffect(() => {
        if (survivor !== null) void navigate(`/w/${workspace.id}/contactos/${survivor}`, { replace: true });
    }, [survivor, navigate, workspace.id]);
    const back = <AutoBreadcrumbs />;

    if (profile.isError) {
        return (
            <>
                {back}
                {isApiError(profile.error, 'not_found') ? (
                    <EmptyState icon={UserX} title={t('contacts.profile.notFound')} description={t('contacts.profile.notFoundHelp')} />
                ) : (
                    <ErrorState error={profile.error} onRetry={() => void profile.refetch()} />
                )}
            </>
        );
    }
    if (profile.isPending) {
        return (
            <>
                {back}
                <Skeleton className="igr:mb-5 igr:h-[420px] igr:rounded-xl igr:sm:h-[330px] igr:xl:h-[262px]" />
                <div className="igr:grid igr:grid-cols-1 igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
                    <Skeleton className="igr:h-96" />
                    <Skeleton className="igr:h-96" />
                </div>
            </>
        );
    }

    const p = profile.data;
    const lead = currentLead(services.data);
    const hasOpenLead = lead !== null && lead.stage_kind === 'open';
    const wa = whatsappDigits(p.phone, p.pii_visible);
    const bookings = business.services && canLeads && (modules === null || modules.includes('bookings'));
    const canGroup = canMarket && canSeeAll;
    // Con las acciones de servicios a la vista, «Agregar a un grupo» pasa al menú (en el celular no caben cuatro botones).
    const groupInMenu = business.services && canLeads;
    const menu = can.merge || can.export || can.remove || (canGroup && groupInMenu);
    const staticGroups = (segments.data ?? []).filter((s) => s.kind === 'static' && !s.is_system).map((s) => ({ id: s.id, name: s.name }));

    const actions = (
        <>
            {p.conversation ? (
                <Button asChild>
                    <Link to={`/w/${workspace.id}/bandeja/${p.conversation.id}`}>
                        <MessageCircle />
                        {t('contacts.actions.write', { channel: t(`contacts.consentChannels.${p.conversation.channel}`, { defaultValue: p.conversation.channel }) })}
                    </Link>
                </Button>
            ) : wa !== null ? (
                <Button asChild>
                    <a href={`https://wa.me/${wa}`} target="_blank" rel="noreferrer">
                        <MessageCircle />
                        {t('contacts.actions.writeWhatsapp')}
                    </a>
                </Button>
            ) : null}
            {bookings ? (
                <Button variant="outline" asChild>
                    <Link to={`/w/${workspace.id}/agenda?nueva=${p.id}`}>
                        <CalendarPlus />
                        {t('contacts.actions.book')}
                    </Link>
                </Button>
            ) : null}
            {business.services && canLeads && services.data && !hasOpenLead ? (
                <Button variant="outline" loading={createLead.isPending} onClick={() => createLead.mutate()}>
                    <Plus />
                    {t('contacts.actions.createLead')}
                </Button>
            ) : null}
            {canGroup && !groupInMenu ? (
                <Button variant="outline" onClick={() => setDialog('group')}>
                    <UsersRound />
                    {t('contacts.actions.addToGroup')}
                </Button>
            ) : null}
            {menu ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="icon" aria-label={t('contacts.actions.menu')}>
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {canGroup && groupInMenu ? (
                            <DropdownMenuItem onSelect={() => setDialog('group')}>
                                <UsersRound />
                                {t('contacts.actions.addToGroup')}
                            </DropdownMenuItem>
                        ) : null}
                        {can.merge ? (
                            <DropdownMenuItem onSelect={() => setDialog('merge')}>
                                <GitMerge />
                                {t('contacts.actions.merge')}
                            </DropdownMenuItem>
                        ) : null}
                        {can.export ? (
                            <DropdownMenuItem onSelect={() => setDialog('export')}>
                                <FileDown />
                                {t('contacts.actions.export')}
                            </DropdownMenuItem>
                        ) : null}
                        {can.remove ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="igr:text-destructive igr:[&_svg]:text-destructive" onSelect={() => setDialog('delete')}>
                                    <Trash2 />
                                    {t('contacts.actions.delete')}
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </>
    );

    const timelineGroups = timelineGroupsFor(business.store, business.services);
    const leadCard = business.services ? <ContactLeadCard contactId={p.id} services={services.data} loading={services.isPending} /> : null;

    return (
        <>
            {back}
            <ProfileHeader profile={p} business={business} services={services.data} servicesLoading={services.isPending && services.fetchStatus !== 'idle'} actions={actions} />
            <div className="igr:grid igr:grid-cols-1 igr:items-start igr:gap-5 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
                <Tabs
                    value={tab}
                    onValueChange={(value) => {
                        const next = new URLSearchParams(params);
                        if (value === 'resumen') {
                            next.delete('tab');
                        } else {
                            next.set('tab', value);
                        }
                        setParams(next, { replace: true });
                    }}
                >
                    <TabsList>
                        {tabs.map((key) => (
                            <TabsTrigger key={key} value={key}>
                                {t(`contacts.tabs.${key}`)}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    <TabsContent value="resumen" className="igr:space-y-4">
                        {!desktop && !business.servicesOnly ? <ProfileSummaryCard contactId={p.id} /> : null}
                        {!desktop ? leadCard : null}
                        <SummaryTab profile={p} servicesOnly={business.servicesOnly} services={services.data} />
                    </TabsContent>
                    {tabs.includes('citas') ? (
                        <TabsContent value="citas">
                            <ServicesTab data={services.data} error={services.error} onRetry={() => void services.refetch()} />
                        </TabsContent>
                    ) : null}
                    {tabs.includes('pedidos') ? (
                        <TabsContent value="pedidos">
                            <OrdersTab contactId={p.id} enabled={canSeeAll} />
                        </TabsContent>
                    ) : null}
                    {tabs.includes('productos') ? (
                        <TabsContent value="productos">
                            <ProductsTab contactId={p.id} />
                        </TabsContent>
                    ) : null}
                    <TabsContent value="mensajes">
                        <MessagesTab contactId={p.id} />
                    </TabsContent>
                    {tabs.includes('fidelizacion') ? (
                        <TabsContent value="fidelizacion">
                            <ContactLoyaltyTab contactId={p.id} />
                        </TabsContent>
                    ) : null}
                    {tabs.includes('actividad') ? (
                        <TabsContent value="actividad">
                            <Timeline contactId={p.id} available={timelineGroups} />
                        </TabsContent>
                    ) : null}
                    {tabs.includes('notas') ? (
                        <TabsContent value="notas">
                            <NotesPanel contactId={p.id} />
                        </TabsContent>
                    ) : null}
                    <TabsContent value="datos">
                        <DataTab key={p.id} profile={p} />
                    </TabsContent>
                    <TabsContent value="identidades">
                        <IdentitiesTab contactId={p.id} />
                    </TabsContent>
                    <TabsContent value="privacidad">
                        <PrivacyTab contactId={p.id} onExport={() => setDialog('export')} onDelete={() => setDialog('delete')} />
                    </TabsContent>
                </Tabs>
                {desktop ? (
                    <aside className="igr:space-y-4" aria-label={t('contacts.profile.aside')}>
                        {/* El resumen con IA habla de compras: sin tienda lo reemplaza el proceso (lead) de la persona. */}
                        {business.servicesOnly ? null : <ProfileSummaryCard contactId={p.id} />}
                        {leadCard}
                        <NotesPanel contactId={p.id} />
                        <Timeline contactId={p.id} available={timelineGroups} />
                    </aside>
                ) : null}
            </div>

            {can.merge ? <MergeDialog profile={p} open={dialog === 'merge'} onOpenChange={(open) => setDialog(open ? 'merge' : null)} /> : null}
            {can.export ? <ExportDialog contactId={p.id} open={dialog === 'export'} onOpenChange={(open) => setDialog(open ? 'export' : null)} /> : null}
            {dialog === 'group' ? (
                <AddToGroupDialog open onOpenChange={(open) => setDialog(open ? 'group' : null)} selection={{ ids: [p.id] }} count={1} groups={staticGroups} onDone={() => undefined} />
            ) : null}
            {can.remove ? (
                <ConfirmDeleteDialog
                    open={dialog === 'delete'}
                    name={p.name !== '' ? p.name : null}
                    onOpenChange={(open) => {
                        if (!open) {
                            remove.reset();
                        }
                        setDialog(open ? 'delete' : null);
                    }}
                    loading={remove.isPending}
                    error={remove.isError ? errorMessage(remove.error, t) : null}
                    onConfirm={(confirm) =>
                        remove.mutate(
                            { kind: 'delete', confirm },
                            {
                                onSuccess: () => {
                                    toast.success(t('privacy.requests.deletedToast'));
                                    void navigate(`/w/${workspace.id}/contactos`, { replace: true });
                                },
                            },
                        )
                    }
                />
            ) : null}
        </>
    );
}
