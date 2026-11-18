import { Copy, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { AiBadge } from './AiBadge';
import { useSuggestSubjects, useSuggestVariants, useSuggestWhatsapp } from './api';
import { copilotError } from './CopilotDialog';

/** Botones «Sugerir» del redactor (inteligencia.md §6.5) para los editores de plantillas. */
function SuggestShell({ title, description, children, label }: { title: string; description: string; children: (close: () => void) => React.ReactNode; label: string }) {
    const [open, setOpen] = useState(false);
    const canUse = useCan('useAi');
    if (!canUse) {
        return null;
    }
    return (
        <>
            <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(true)}>
                <Sparkles />
                {label}
            </Button>
            {open ? (
                <Dialog open onOpenChange={(o) => !o && setOpen(false)}>
                    <DialogContent className="igr:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </DialogHeader>
                        {children(() => setOpen(false))}
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

function BriefFields({ goal, offer, setGoal, setOffer, idPrefix }: { goal: string; offer: string; setGoal: (v: string) => void; setOffer: (v: string) => void; idPrefix: string }) {
    const { t } = useTranslation();
    return (
        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
            <div>
                <Label htmlFor={`${idPrefix}-goal`}>{t('copilot.suggest.goal')}</Label>
                <Input id={`${idPrefix}-goal`} value={goal} maxLength={500} placeholder={t('copilot.suggest.goalPlaceholder')} onChange={(e) => setGoal(e.target.value)} />
            </div>
            <div>
                <Label htmlFor={`${idPrefix}-offer`}>{t('copilot.suggest.offer')}</Label>
                <Input id={`${idPrefix}-offer`} value={offer} maxLength={300} placeholder={t('copilot.suggest.offerPlaceholder')} onChange={(e) => setOffer(e.target.value)} />
            </div>
        </div>
    );
}

export function SuggestSubjectButton({ onPick }: { onPick: (subject: string, preheader: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const suggest = useSuggestSubjects(workspace.id);
    const [goal, setGoal] = useState('');
    const [offer, setOffer] = useState('');

    return (
        <SuggestShell label={t('copilot.suggest.subjects')} title={t('copilot.suggest.subjectsTitle')} description={t('copilot.suggest.subjectsHelp')}>
            {(close) => (
                <div className="igr:space-y-3">
                    <BriefFields goal={goal} offer={offer} setGoal={setGoal} setOffer={setOffer} idPrefix="sg-sub" />
                    <Button type="button" size="sm" loading={suggest.isPending} disabled={goal.trim().length < 3} onClick={() => suggest.mutate({ goal, offer: offer || null, count: 5 })}>
                        <Sparkles />
                        {t('copilot.suggest.generate')}
                    </Button>
                    <FieldError message={suggest.isError ? copilotError(suggest.error, t('copilot.errors.generic')) : undefined} />
                    {suggest.data ? (
                        <ul className="igr:space-y-1.5">
                            <li>
                                <AiBadge />
                            </li>
                            {suggest.data.variants.map((v) => (
                                <li key={v.subject}>
                                    <button
                                        type="button"
                                        className="igr:w-full igr:rounded-md igr:border igr:p-2 igr:text-left igr:hover:bg-accent igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                                        onClick={() => {
                                            onPick(v.subject, v.preheader);
                                            close();
                                        }}
                                    >
                                        <span className="igr:block igr:text-[13px] igr:font-medium">{v.subject}</span>
                                        <span className="igr:block igr:text-xs igr:text-muted-foreground">{v.preheader}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            )}
        </SuggestShell>
    );
}

export function SuggestVariantsButton({ text, kind, onPick }: { text: string; kind: 'subject' | 'body' | 'whatsapp'; onPick: (value: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const suggest = useSuggestVariants(workspace.id);

    return (
        <SuggestShell label={t('copilot.suggest.variants')} title={t('copilot.suggest.variantsTitle')} description={t('copilot.suggest.variantsHelp')}>
            {(close) => (
                <div className="igr:space-y-3">
                    <p className="igr:rounded-md igr:bg-muted igr:p-2 igr:text-[13px]">{text || t('copilot.suggest.noText')}</p>
                    <Button type="button" size="sm" loading={suggest.isPending} disabled={text.trim().length < 2} onClick={() => suggest.mutate({ text, kind, count: 3 })}>
                        <Sparkles />
                        {t('copilot.suggest.generate')}
                    </Button>
                    <FieldError message={suggest.isError ? copilotError(suggest.error, t('copilot.errors.generic')) : undefined} />
                    {suggest.data ? (
                        <ul className="igr:space-y-1.5">
                            {suggest.data.variants.map((v) => (
                                <li key={v}>
                                    <button
                                        type="button"
                                        className="igr:w-full igr:rounded-md igr:border igr:p-2 igr:text-left igr:text-[13px] igr:hover:bg-accent igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                                        onClick={() => {
                                            onPick(v);
                                            close();
                                        }}
                                    >
                                        {v}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            )}
        </SuggestShell>
    );
}

/** Texto para una plantilla nueva de WhatsApp que se envía a aprobación de Meta. */
export function SuggestWhatsappButton() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const suggest = useSuggestWhatsapp(workspace.id);
    const [goal, setGoal] = useState('');
    const [offer, setOffer] = useState('');
    const [category, setCategory] = useState<'marketing' | 'utility'>('marketing');
    const [copied, setCopied] = useState(false);

    return (
        <SuggestShell label={t('copilot.suggest.whatsapp')} title={t('copilot.suggest.whatsappTitle')} description={t('copilot.suggest.whatsappHelp')}>
            {() => (
                <div className="igr:space-y-3">
                    <BriefFields goal={goal} offer={offer} setGoal={setGoal} setOffer={setOffer} idPrefix="sg-wa" />
                    <div>
                        <Label htmlFor="sg-wa-cat">{t('copilot.suggest.category')}</Label>
                        <Select id="sg-wa-cat" value={category} onChange={(e) => setCategory(e.target.value === 'utility' ? 'utility' : 'marketing')}>
                            <option value="marketing">{t('copilot.suggest.categories.marketing')}</option>
                            <option value="utility">{t('copilot.suggest.categories.utility')}</option>
                        </Select>
                    </div>
                    <Button type="button" size="sm" loading={suggest.isPending} disabled={goal.trim().length < 3} onClick={() => suggest.mutate({ goal, offer: offer || null, category })}>
                        <Sparkles />
                        {t('copilot.suggest.generate')}
                    </Button>
                    <FieldError message={suggest.isError ? copilotError(suggest.error, t('copilot.errors.generic')) : undefined} />
                    {suggest.data ? (
                        <div className="igr:space-y-2">
                            <div className="igr:flex igr:items-center igr:justify-between">
                                <AiBadge />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        void navigator.clipboard.writeText(suggest.data.body).then(() => setCopied(true));
                                    }}
                                >
                                    <Copy />
                                    {copied ? t('copilot.suggest.copied') : t('copilot.suggest.copy')}
                                </Button>
                            </div>
                            <Textarea readOnly rows={5} value={suggest.data.body} aria-label={t('copilot.suggest.whatsappBody')} />
                            <ul className="igr:text-xs igr:text-muted-foreground">
                                {suggest.data.variables.map((v) => (
                                    <li key={v.index}>{t('copilot.suggest.variable', { n: v.index, meaning: v.meaning, example: v.example })}</li>
                                ))}
                            </ul>
                            {suggest.data.problems.length > 0 ? <FieldError message={suggest.data.problems.join(' ')} /> : <p className="igr:text-xs igr:text-success">{t('copilot.suggest.rulesOk')}</p>}
                        </div>
                    ) : null}
                </div>
            )}
        </SuggestShell>
    );
}
