import { Mail, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import { errorMessage } from '@/features/contacts/errors';
import { useUseGroup } from './api';
import type { SegmentSummary } from './types';

/**
 * «Enviar campaña a este grupo» y «Crear automatización»: deja la campaña con el canal y el grupo como audiencia y abre
 * el paso del mensaje, o deja la automatización que arranca cuando alguien entra al grupo y abre el constructor. Las dos
 * quedan en borrador: nada sale sin revisar.
 */
export function UseGroupDialog({ segment, mode, onClose }: { segment: Pick<SegmentSummary, 'id' | 'name' | 'channel_counts'>; mode: 'campaign' | 'flow'; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const use = useUseGroup(workspace.id);
    const [channel, setChannel] = useState<'whatsapp' | 'email'>((segment.channel_counts.whatsapp ?? 0) >= (segment.channel_counts.email ?? 0) ? 'whatsapp' : 'email');
    const [name, setName] = useState(t(mode === 'campaign' ? 'segments.use.campaignName' : 'segments.use.flowName', { name: segment.name }));
    const mutation = mode === 'campaign' ? use.campaign : use.flow;
    const base = `/w/${workspace.id}`;

    const submit = () => {
        if (mode === 'campaign') {
            use.campaign.mutate({ segmentId: segment.id, name: name.trim(), channel }, { onSuccess: (c) => void navigate(`${base}/campanas/${c.id}/editar?paso=content`) });
        } else {
            use.flow.mutate({ segmentId: segment.id, name: name.trim() }, { onSuccess: (f) => void navigate(`${base}/flujos/${f.id}`) });
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t(mode === 'campaign' ? 'segments.use.campaignTitle' : 'segments.use.flowTitle', { name: segment.name })}</DialogTitle>
                    <DialogDescription>{t(mode === 'campaign' ? 'segments.use.campaignHelp' : 'segments.use.flowHelp')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (name.trim() !== '' && !mutation.isPending) {
                            submit();
                        }
                    }}
                >
                    {mode === 'campaign' ? (
                        <fieldset>
                            <legend className="igr:mb-1.5 igr:text-[13px] igr:font-medium">{t('segments.use.channel')}</legend>
                            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                                {(['whatsapp', 'email'] as const).map((c) => {
                                    const Icon = c === 'whatsapp' ? MessageCircle : Mail;
                                    const reach = segment.channel_counts[c] ?? 0;
                                    return (
                                        <label
                                            key={c}
                                            className={cn(
                                                'igr:flex igr:cursor-pointer igr:items-start igr:gap-2.5 igr:rounded-lg igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-3 igr:has-[:focus-visible]:ring-ring/30',
                                                channel === c ? 'igr:border-primary/50 igr:bg-brand-soft/60' : 'igr:hover:bg-accent',
                                            )}
                                        >
                                            <input type="radio" name="use-channel" className="igr:sr-only" checked={channel === c} onChange={() => setChannel(c)} />
                                            <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-primary" aria-hidden />
                                            <span>
                                                <span className="igr:block igr:text-body igr:font-medium">{t(`segments.use.${c}`)}</span>
                                                <span className="igr:block igr:text-support igr:text-muted-foreground">
                                                    {t('segments.use.reach', { count: reach, formatted: formatNumber(reach, workspace.locale) })}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    ) : null}
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="use-name">{t(mode === 'campaign' ? 'segments.use.campaignLabel' : 'segments.use.flowLabel')}</Label>
                        <Input id="use-name" value={name} maxLength={160} onChange={(e) => setName(e.target.value)} />
                    </div>
                    <FieldError message={mutation.isError ? errorMessage(mutation.error, t) : undefined} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={mutation.isPending} disabled={name.trim() === ''}>
                            {t(mode === 'campaign' ? 'segments.use.campaignSubmit' : 'segments.use.flowSubmit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
