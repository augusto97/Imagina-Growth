import { Plus, Target } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { errorMessage } from '@/features/contacts/errors';
import { leadKeys, useLeadList } from './api';
import { temperatureTone } from './logic';
import type { LeadDetail } from './types';

/** Lead del contacto en su perfil (M18): etapa, puntaje, vendedor y valor, o crear uno a mano. */
export function ContactLeadCard({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canUse = useCan('useLeads');
    const qc = useQueryClient();
    const list = useLeadList(workspace.id, { contact: contactId, sort: 'recent' });
    const create = useMutation({
        mutationFn: () => api.post<LeadDetail>(`${wsPath(workspace.id)}/leads`, { contact_id: contactId }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: leadKeys.all(workspace.id) }),
        onError: (e) => toast.error(errorMessage(e, t)),
    });
    if (!canUse || list.isError) return null;
    const lead = list.data?.data[0];
    const stage = lead ? (list.data?.pipeline.stages.find((s) => s.key === lead.stage)?.label ?? lead.stage) : null;

    return (
        <Card>
            <CardHeader className="igr:pb-2">
                <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                    <Target className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    {t('leads.profile.title')}
                </CardTitle>
            </CardHeader>
            <CardContent className="igr:text-[13px]">
                {list.isPending ? (
                    <Skeleton className="igr:h-12" />
                ) : lead ? (
                    <div className="igr:space-y-1.5">
                        <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                            <span className="igr:font-medium">{stage}</span>
                            <Badge variant={temperatureTone(lead.temperature)}>
                                {lead.score} · {t(`leads.temperature.${lead.temperature}`)}
                            </Badge>
                        </div>
                        <p className="igr:text-muted-foreground">
                            {[lead.service, lead.owner?.name ?? t('leads.unassigned'), lead.value !== null ? formatMoney(lead.value, lead.currency, workspace.locale) : null].filter(Boolean).join(' · ')}
                        </p>
                        <Link to={`/w/${workspace.id}/leads?lead=${lead.id}`} className="igr:text-primary igr:hover:underline">
                            {t('leads.profile.open')}
                        </Link>
                    </div>
                ) : (
                    <div className="igr:space-y-2">
                        <p className="igr:text-muted-foreground">{t('leads.profile.none')}</p>
                        <Button size="sm" variant="outline" disabled={create.isPending} onClick={() => create.mutate()}>
                            <Plus />
                            {t('leads.profile.create')}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
