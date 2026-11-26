import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Plus, Target } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { wsPath } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ContactServices, contactKeys } from '@/features/contacts/api';
import { errorMessage } from '@/features/contacts/errors';
import { currentLead } from '@/features/contacts/profile/insights';
import { leadKeys } from './api';
import { temperatureTone } from './logic';
import type { LeadDetail } from './types';

/** Crea un lead a mano para una persona (origen `manual`) y refresca su perfil y Leads. */
export function useCreateContactLead(contactId: string) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<LeadDetail>(`${wsPath(workspace.id)}/leads`, { contact_id: contactId }),
        onSuccess: () => {
            toast.success(t('leads.profile.created'));
            void qc.invalidateQueries({ queryKey: leadKeys.all(workspace.id) });
            void qc.invalidateQueries({ queryKey: contactKeys.part(workspace.id, contactId, 'services') });
        },
        onError: (e) => toast.error(errorMessage(e, t)),
    });
}

/**
 * El proceso de la persona en su perfil (M18): la etapa, la temperatura, el servicio y el valor, con el enlace a su ficha;
 * o «Crear lead» si no tiene uno. Usa lo de servicios del perfil (`GET services/contacts/{id}`), sin pedir otra vez.
 */
export function ContactLeadCard({ contactId, services, loading }: { contactId: string; services: ContactServices | undefined; loading: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canUse = useCan('useLeads');
    const create = useCreateContactLead(contactId);
    if (!canUse) return null;
    const lead = currentLead(services);
    const open = lead !== null && lead.stage_kind === 'open';

    return (
        <Card>
            <CardHeader className="igr:pb-2">
                <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                    <Target className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    {t('leads.profile.title')}
                </CardTitle>
            </CardHeader>
            <CardContent className="igr:text-[13px]">
                {loading && services === undefined ? (
                    <Skeleton className="igr:h-12" />
                ) : lead ? (
                    <div className="igr:space-y-1.5">
                        <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                            <span className="igr:font-medium">{lead.stage_label}</span>
                            {open ? <Badge variant={temperatureTone(lead.temperature)}>{t(`leads.temperature.${lead.temperature}`)}</Badge> : null}
                        </div>
                        <p className="igr:text-muted-foreground">
                            {[lead.service, lead.value !== null ? formatMoney(lead.value, services?.currency ?? workspace.currency, workspace.locale) : null].filter(Boolean).join(' · ') ||
                                t('leads.noService')}
                        </p>
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:pt-1">
                            <Button asChild size="sm" variant="outline">
                                <Link to={`/w/${workspace.id}/leads?lead=${lead.id}`}>
                                    {t('leads.profile.open')}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                            {!open ? (
                                <Button size="sm" variant="ghost" loading={create.isPending} onClick={() => create.mutate()}>
                                    <Plus />
                                    {t('leads.profile.createAnother')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="igr:space-y-2">
                        <p className="igr:text-muted-foreground">{t('leads.profile.none')}</p>
                        <Button size="sm" variant="outline" loading={create.isPending} onClick={() => create.mutate()}>
                            <Plus />
                            {t('leads.profile.create')}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
