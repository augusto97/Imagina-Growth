import { ArrowLeft, FileDown, GitMerge, Gift, MessageSquare, MoreHorizontal, Trash2, UserX } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { ConfirmDeleteDialog } from '@/features/privacy/ConfirmDeleteDialog';
import { ExportDialog } from '@/features/privacy/ExportDialog';
import { useRunDataRequest } from '@/features/privacy/api';
import { useContactAbilities } from '../abilities';
import { useContactProfile } from '../api';
import { errorMessage } from '../errors';
import { DataTab } from './DataTab';
import { IdentitiesTab } from './IdentitiesTab';
import { MergeDialog } from './MergeDialog';
import { NotesPanel } from './NotesPanel';
import { OrdersTab } from './OrdersTab';
import { PrivacyTab } from './PrivacyTab';
import { ProductsTab } from './ProductsTab';
import { ProfileHeader } from './ProfileHeader';
import { SummaryTab } from './SummaryTab';
import { Timeline } from './Timeline';

const TABS = ['resumen', 'pedidos', 'productos', 'mensajes', 'fidelizacion', 'datos', 'identidades', 'privacidad'] as const;
type Tab = (typeof TABS)[number];

/** Perfil 360 (modulos.md M03). */
export function ProfilePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { contactId = '' } = useParams();
    const navigate = useNavigate();
    const can = useContactAbilities();
    const [params, setParams] = useSearchParams();
    const profile = useContactProfile(workspace.id, contactId);
    const [dialog, setDialog] = useState<'merge' | 'export' | 'delete' | null>(null);
    const remove = useRunDataRequest(workspace.id, contactId);
    const tabParam = params.get('tab');
    const tab: Tab = TABS.find((x) => x === tabParam) ?? 'resumen';
    const back = (
        <Link to={`/w/${workspace.id}/contactos`} className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground">
            <ArrowLeft className="igr:size-3.5" />
            {t('contacts.title')}
        </Link>
    );

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
                <Skeleton className="igr:mb-4 igr:h-44" />
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
                    <Skeleton className="igr:h-96" />
                    <Skeleton className="igr:h-96" />
                </div>
            </>
        );
    }

    const p = profile.data;
    const anyAction = can.merge || can.export || can.remove;

    const actions = anyAction ? (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" aria-label={t('contacts.actions.menu')}>
                    <MoreHorizontal />
                    {t('contacts.actions.menu')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {can.merge && (
                    <DropdownMenuItem onSelect={() => setDialog('merge')}>
                        <GitMerge />
                        {t('contacts.actions.merge')}
                    </DropdownMenuItem>
                )}
                {can.export && (
                    <DropdownMenuItem onSelect={() => setDialog('export')}>
                        <FileDown />
                        {t('contacts.actions.export')}
                    </DropdownMenuItem>
                )}
                {can.remove && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem className="igr:text-destructive igr:[&_svg]:text-destructive" onSelect={() => setDialog('delete')}>
                            <Trash2 />
                            {t('contacts.actions.delete')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    ) : null;

    return (
        <>
            {back}
            <ProfileHeader profile={p} actions={actions} />
            <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
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
                    <TabsList className="igr:overflow-x-auto">
                        {TABS.map((key) => (
                            <TabsTrigger key={key} value={key} className="igr:shrink-0">
                                {t(`contacts.tabs.${key}`)}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    <TabsContent value="resumen">
                        <SummaryTab profile={p} />
                    </TabsContent>
                    <TabsContent value="pedidos">
                        <OrdersTab contactId={p.id} enabled={(workspace.abilities as readonly string[]).includes('viewAllContacts')} />
                    </TabsContent>
                    <TabsContent value="productos">
                        <ProductsTab contactId={p.id} />
                    </TabsContent>
                    <TabsContent value="mensajes">
                        <EmptyState icon={MessageSquare} title={t('contacts.messages.title')} description={t('contacts.messages.description')} />
                    </TabsContent>
                    <TabsContent value="fidelizacion">
                        <EmptyState icon={Gift} title={t('contacts.loyalty.title')} description={t('contacts.loyalty.description')} />
                    </TabsContent>
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
                <aside className="igr:space-y-4">
                    <NotesPanel contactId={p.id} />
                    <Timeline contactId={p.id} />
                </aside>
            </div>

            {can.merge && <MergeDialog profile={p} open={dialog === 'merge'} onOpenChange={(open) => setDialog(open ? 'merge' : null)} />}
            {can.export && <ExportDialog contactId={p.id} open={dialog === 'export'} onOpenChange={(open) => setDialog(open ? 'export' : null)} />}
            {can.remove && (
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
            )}
        </>
    );
}
