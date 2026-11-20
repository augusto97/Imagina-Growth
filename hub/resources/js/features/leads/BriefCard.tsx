import { Pencil, Sparkles, UserRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useUpdateBrief } from './api';
import { briefDraft, briefEdits, briefFilled } from './logic';
import { BRIEF_KEYS, type Brief, type BriefField, type BriefKey } from './types';

/**
 * La ficha del lead que llena la IA con la conversación (M18): cada dato dice si lo
 * llenó la IA (con la frase del cliente de la que salió) o una persona. Editar un
 * dato lo deja como tuyo: la IA ya no lo cambia.
 */
export function BriefCard({ leadId, brief, editable, compact = false }: { leadId: string; brief: Brief; editable: boolean; compact?: boolean }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const filled = briefFilled(brief);

    if (editing) {
        return <BriefForm leadId={leadId} brief={brief} onDone={() => setEditing(false)} />;
    }

    return (
        <section aria-labelledby={`brief-${leadId}`}>
            <div className="igr:mb-1.5 igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h3
                    id={`brief-${leadId}`}
                    className={compact ? 'igr:text-[11px] igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground' : 'igr:text-[13px] igr:font-medium'}
                >
                    {t('leads.brief.title')}
                </h3>
                {editable ? (
                    <Button type="button" size="sm" variant="ghost" className="igr:h-7" onClick={() => setEditing(true)}>
                        <Pencil aria-hidden />
                        {t('leads.brief.edit')}
                    </Button>
                ) : null}
            </div>
            {filled === 0 ? (
                <p className="igr:text-xs igr:text-muted-foreground">{t('leads.brief.empty')}</p>
            ) : (
                <dl className={compact ? 'igr:space-y-1.5' : 'igr:grid igr:gap-x-4 igr:gap-y-2 igr:sm:grid-cols-2'}>
                    {BRIEF_KEYS.map((key) => {
                        const field = brief[key];
                        if (!field || field.value === null) return null;
                        return <BriefRow key={key} field={field} name={key} />;
                    })}
                </dl>
            )}
            {!compact ? <p className="igr:mt-2 igr:text-[11.5px] igr:text-muted-foreground">{t('leads.brief.help')}</p> : null}
        </section>
    );
}

function BriefRow({ name, field }: { name: BriefKey; field: BriefField }) {
    const { t } = useTranslation();
    const value = name === 'temperature' ? t(`leads.temperature.${field.value ?? 'cold'}`) : (field.value ?? '');
    return (
        <div className="igr:min-w-0 igr:text-[13px]">
            <dt className="igr:text-[11.5px] igr:text-muted-foreground">{t(`leads.brief.fields.${name}`)}</dt>
            <dd className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                <span className="igr:font-medium">{value}</span>
                {field.by === 'ai' ? (
                    <Tooltip content={field.quote ? t('leads.brief.fromQuote', { quote: field.quote }) : t('leads.brief.fromAi')}>
                        <Badge variant="default" tabIndex={0} aria-label={t('leads.brief.aiLabel')}>
                            <Sparkles className="igr:size-3" aria-hidden />
                            {t('leads.brief.ai')}
                        </Badge>
                    </Tooltip>
                ) : (
                    <Badge variant="outline">
                        <UserRound className="igr:size-3" aria-hidden />
                        {t('leads.brief.byPerson')}
                    </Badge>
                )}
            </dd>
            {name === 'temperature' && field.reason ? <p className="igr:text-xs igr:text-muted-foreground">{field.reason}</p> : null}
            {field.by === 'ai' && field.quote ? <p className="igr:truncate igr:text-xs igr:italic igr:text-muted-foreground">«{field.quote}»</p> : null}
        </div>
    );
}

function BriefForm({ leadId, brief, onDone }: { leadId: string; brief: Brief; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const update = useUpdateBrief(workspace.id);
    const [draft, setDraft] = useState(() => briefDraft(brief));
    return (
        <form
            className="igr:space-y-3 igr:rounded-md igr:border igr:p-3"
            onSubmit={(e) => {
                e.preventDefault();
                const fields = briefEdits(brief, draft);
                if (Object.keys(fields).length === 0) {
                    onDone();
                    return;
                }
                update.mutate(
                    { id: leadId, fields },
                    {
                        onSuccess: () => {
                            toast.success(t('leads.brief.saved'));
                            onDone();
                        },
                        onError: (err) => toast.error(errorMessage(err, t)),
                    },
                );
            }}
        >
            <p className="igr:text-xs igr:text-muted-foreground">{t('leads.brief.editHelp')}</p>
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                {BRIEF_KEYS.map((key) => (
                    <div key={key} className="igr:space-y-1">
                        <Label htmlFor={`brief-${leadId}-${key}`}>{t(`leads.brief.fields.${key}`)}</Label>
                        {key === 'temperature' ? (
                            <Select id={`brief-${leadId}-${key}`} value={draft.temperature} onChange={(e) => setDraft({ ...draft, temperature: e.target.value })}>
                                <option value="">—</option>
                                {(['hot', 'warm', 'cold'] as const).map((k) => (
                                    <option key={k} value={k}>
                                        {t(`leads.temperature.${k}`)}
                                    </option>
                                ))}
                            </Select>
                        ) : (
                            <Input
                                id={`brief-${leadId}-${key}`}
                                maxLength={key === 'need' ? 160 : 80}
                                value={draft[key]}
                                onChange={(e) => setDraft({ ...draft, [key]: e.target.value })}
                            />
                        )}
                    </div>
                ))}
            </div>
            <div className="igr:flex igr:gap-2">
                <Button type="submit" size="sm" disabled={update.isPending}>
                    {t('common.save')}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    {t('leads.brief.cancel')}
                </Button>
            </div>
        </form>
    );
}
