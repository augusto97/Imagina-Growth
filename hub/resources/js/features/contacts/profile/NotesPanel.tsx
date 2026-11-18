import { StickyNote, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useContactAbilities } from '../abilities';
import { useAddNote, useContactNotes, useDeleteNote } from '../api';
import { errorMessage } from '../errors';

/** Notas internas del equipo (no las ve el contacto). */
export function NotesPanel({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const notes = useContactNotes(workspace.id, contactId);
    const add = useAddNote(workspace.id, contactId);
    const remove = useDeleteNote(workspace.id, contactId);
    const [body, setBody] = useState('');

    const submit = () => {
        const text = body.trim();
        if (text === '') {
            return;
        }
        add.mutate(text, {
            onSuccess: () => setBody(''),
            onError: (error) => toast.error(errorMessage(error, t)),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('contacts.notes.title')}</CardTitle>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                {can.edit && (
                    <form
                        className="igr:space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                    >
                        <Textarea
                            aria-label={t('contacts.notes.placeholder')}
                            placeholder={t('contacts.notes.placeholder')}
                            className="igr:min-h-16"
                            maxLength={5000}
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                    e.preventDefault();
                                    submit();
                                }
                            }}
                        />
                        <div className="igr:flex igr:items-center igr:justify-between">
                            <span className="igr:text-[11px] igr:text-muted-foreground">
                                <Kbd>Ctrl</Kbd> + <Kbd>Enter</Kbd>
                            </span>
                            <Button type="submit" size="sm" disabled={body.trim() === ''} loading={add.isPending}>
                                {t('contacts.notes.add')}
                            </Button>
                        </div>
                    </form>
                )}
                {notes.isError ? (
                    <ErrorState error={notes.error} onRetry={() => void notes.refetch()} />
                ) : notes.isPending ? (
                    <Skeleton className="igr:h-12" />
                ) : notes.data.length === 0 ? (
                    <p className="igr:flex igr:items-center igr:gap-2 igr:text-xs igr:text-muted-foreground">
                        <StickyNote className="igr:size-3.5" />
                        {t('contacts.notes.empty')}
                    </p>
                ) : (
                    <ul className="igr:space-y-2">
                        {notes.data.map((n) => (
                            <li key={n.id} className="igr:group igr:rounded-md igr:border igr:bg-muted/40 igr:p-2.5">
                                <p className="igr:whitespace-pre-wrap igr:break-words igr:text-[13px]">{n.body}</p>
                                <div className="igr:mt-1.5 igr:flex igr:items-center igr:justify-between igr:text-[11px] igr:text-muted-foreground">
                                    <Tooltip content={formatDateTime(n.created_at, workspace.locale, workspace.timezone)}>
                                        <span tabIndex={0}>
                                            {n.author ?? t('contacts.notes.system')} · {formatRelative(n.created_at, workspace.locale)}
                                        </span>
                                    </Tooltip>
                                    {(n.mine || can.manage) && (
                                        <button
                                            type="button"
                                            className="igr:rounded igr:p-0.5 igr:opacity-0 igr:outline-none igr:transition-opacity igr:hover:text-destructive igr:focus-visible:opacity-100 igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:group-hover:opacity-100"
                                            aria-label={t('contacts.notes.delete')}
                                            onClick={() => remove.mutate(n.id, { onError: (error) => toast.error(errorMessage(error, t)) })}
                                        >
                                            <Trash2 className="igr:size-3.5" />
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
