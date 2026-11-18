import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { useApplyDraft, useCopilotStatus, useDiscardDraft, usePropose } from './api';
import { CampaignProposalView, FlowProposalView } from './ProposalViews';
import type { Draft } from './types';

/** «Crear con IA» en Campañas y Flujos: pedido → propuesta revisable → borrador (nunca envía ni activa). */
export function CreateWithAiButton({ kind, label }: { kind: 'campaign' | 'flow'; label?: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const canUse = useCan('useAi');
    if (!canUse) {
        return null;
    }
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Sparkles />
                {label ?? t('copilot.createWithAi')}
            </Button>
            {open ? <CopilotDialog kind={kind} onClose={() => setOpen(false)} /> : null}
        </>
    );
}

export function copilotError(error: unknown, fallback: string): string {
    if (isApiError(error)) {
        const fields = Object.values(error.fieldErrors);
        return fields.length > 0 && error.status === 422 ? `${error.message} ${fields.slice(0, 3).join(' ')}` : error.message;
    }
    return fallback;
}

export function CopilotDialog({ kind, onClose, initial }: { kind: 'campaign' | 'flow'; onClose: () => void; initial?: Draft }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const status = useCopilotStatus(workspace.id);
    const propose = usePropose(workspace.id, kind);
    const apply = useApplyDraft(workspace.id);
    const discard = useDiscardDraft(workspace.id);
    const canApply = useCan('manageMarketing');
    const [prompt, setPrompt] = useState('');
    const [draft, setDraft] = useState<Draft | null>(initial ?? null);
    const examples = t(`copilot.examples.${kind}`, { returnObjects: true }) as string[];

    const onApply = () => {
        if (draft === null) {
            return;
        }
        apply.mutate(draft.id, {
            onSuccess: (d) => {
                const first = d.proposal.applied?.[0];
                onClose();
                if (first) {
                    void navigate(d.kind === 'flow' ? `/w/${workspace.id}/flujos/${first.id}` : `/w/${workspace.id}/campanas/${first.id}/editar`);
                }
            },
        });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-3xl igr:top-[6vh] igr:max-h-[88vh] igr:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="igr:flex igr:items-center igr:gap-2">
                        <Sparkles className="igr:size-4 igr:text-primary" aria-hidden="true" />
                        {t(`copilot.dialog.title.${kind}`)}
                    </DialogTitle>
                    <DialogDescription>{t(`copilot.dialog.description.${kind}`)}</DialogDescription>
                </DialogHeader>

                {draft === null ? (
                    <form
                        className="igr:space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            propose.mutate(prompt.trim(), { onSuccess: (d) => setDraft(d) });
                        }}
                    >
                        {status.data && !status.data.available ? (
                            <p role="status" className="igr:rounded-md igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-2 igr:text-xs">
                                {t('copilot.unavailable')}
                            </p>
                        ) : null}
                        <div>
                            <Label htmlFor="copilot-prompt">{t('copilot.dialog.prompt')}</Label>
                            <Textarea
                                id="copilot-prompt"
                                className="igr:mt-1"
                                rows={4}
                                value={prompt}
                                maxLength={1000}
                                required
                                autoFocus
                                disabled={propose.isPending}
                                placeholder={examples[0]}
                                onChange={(e) => setPrompt(e.target.value)}
                            />
                        </div>
                        <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                            {examples.map((ex) => (
                                <button
                                    key={ex}
                                    type="button"
                                    className="igr:rounded-full igr:border igr:px-2 igr:py-0.5 igr:text-[11px] igr:text-muted-foreground igr:hover:text-foreground igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                                    onClick={() => setPrompt(ex)}
                                >
                                    {ex}
                                </button>
                            ))}
                        </div>
                        {propose.isPending ? (
                            <div className="igr:space-y-2" aria-live="polite">
                                <p className="igr:text-xs igr:text-muted-foreground">{t(`copilot.dialog.thinking.${kind}`)}</p>
                                <Skeleton className="igr:h-16" />
                                <Skeleton className="igr:h-24" />
                            </div>
                        ) : null}
                        <FieldError message={propose.isError ? copilotError(propose.error, t('copilot.errors.generic')) : undefined} />
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" loading={propose.isPending} disabled={prompt.trim().length < 8}>
                                <Sparkles />
                                {t('copilot.dialog.propose')}
                            </Button>
                        </DialogFooter>
                    </form>
                ) : (
                    <div className="igr:space-y-4">
                        {draft.kind === 'campaign' ? <CampaignProposalView draft={draft} onChange={setDraft} editable={draft.status === 'draft'} /> : <FlowProposalView draft={draft} />}
                        <FieldError message={apply.isError ? copilotError(apply.error, t('copilot.errors.generic')) : undefined} />
                        <DialogFooter>
                            {draft.status === 'draft' ? (
                                <>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        loading={discard.isPending}
                                        onClick={() => discard.mutate(draft.id, { onSuccess: onClose })}
                                    >
                                        {t('copilot.dialog.discard')}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setDraft(null)}>
                                        {t('copilot.dialog.again')}
                                    </Button>
                                    {canApply ? (
                                        <Button type="button" loading={apply.isPending} onClick={onApply}>
                                            {t(`copilot.dialog.apply.${draft.kind}`)}
                                        </Button>
                                    ) : null}
                                </>
                            ) : (
                                <Button type="button" variant="outline" onClick={onClose}>
                                    {t('common.close')}
                                </Button>
                            )}
                        </DialogFooter>
                        {draft.status === 'draft' ? <p className="igr:text-[11px] igr:text-muted-foreground">{t(`copilot.dialog.applyHelp.${draft.kind}`)}</p> : null}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
