import { ListTodo, Phone, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLeadTasks } from './api';
import type { LeadTask } from './types';

/** Tareas y llamadas del lead: salen en «Hoy» del vendedor el día que vencen. */
export function TasksCard({ leadId, tasks }: { leadId: string; tasks: LeadTask[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { create, update } = useLeadTasks(workspace.id);
    const [title, setTitle] = useState('');
    const [kind, setKind] = useState<'task' | 'call'>('call');
    const [due, setDue] = useState(() => new Date(Date.now() + 86_400_000).toISOString().slice(0, 10));
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    return (
        <section aria-labelledby={`tasks-${leadId}`}>
            <div className="igr:mb-2 igr:flex igr:items-center igr:justify-between">
                <h3 id={`tasks-${leadId}`} className="igr:text-[13px] igr:font-medium">
                    {t('leads.tasks.title')}
                </h3>
                <Link to={`/w/${workspace.id}/hoy`} className="igr:text-xs igr:text-primary igr:hover:underline">
                    {t('leads.tasks.today')}
                </Link>
            </div>
            {tasks.length > 0 ? (
                <ul className="igr:mb-3 igr:space-y-1.5">
                    {tasks.map((task) => (
                        <li key={task.id} className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input
                                type="checkbox"
                                className="igr:mt-0.5 igr:size-4"
                                aria-label={t('leads.tasks.markDone', { title: task.title })}
                                checked={task.status === 'done'}
                                disabled={update.isPending}
                                onChange={(e) => update.mutate({ id: task.id, status: e.target.checked ? 'done' : 'open' }, { onError: fail })}
                            />
                            {task.kind === 'call' ? (
                                <Phone className="igr:mt-0.5 igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                            ) : (
                                <ListTodo className="igr:mt-0.5 igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                            )}
                            <span className={task.status === 'done' ? 'igr:text-muted-foreground igr:line-through' : undefined}>
                                {task.title}
                                <span className="igr:block igr:text-xs igr:text-muted-foreground">
                                    {formatDateTime(task.due_at, workspace.locale, workspace.timezone)}
                                    {task.source === 'flow' ? ` · ${t('leads.tasks.fromFlow')}` : ''}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}
            <form
                className="igr:grid igr:items-end igr:gap-2 igr:sm:grid-cols-[1fr_auto_auto_auto]"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (title.trim() === '') return;
                    create.mutate(
                        { lead: leadId, title: title.trim(), kind, due_at: new Date(`${due}T09:00:00`).toISOString() },
                        {
                            onSuccess: () => {
                                setTitle('');
                                toast.success(t('leads.tasks.created'));
                            },
                            onError: fail,
                        },
                    );
                }}
            >
                <div className="igr:space-y-1">
                    <Label htmlFor={`task-title-${leadId}`}>{t('leads.tasks.new')}</Label>
                    <Input id={`task-title-${leadId}`} maxLength={160} value={title} placeholder={t('leads.tasks.placeholder')} onChange={(e) => setTitle(e.target.value)} />
                </div>
                <Select aria-label={t('leads.tasks.kind')} value={kind} onChange={(e) => setKind(e.target.value === 'task' ? 'task' : 'call')}>
                    <option value="call">{t('leads.tasks.kinds.call')}</option>
                    <option value="task">{t('leads.tasks.kinds.task')}</option>
                </Select>
                <Input type="date" aria-label={t('leads.tasks.due')} value={due} onChange={(e) => setDue(e.target.value)} />
                <Button type="submit" size="sm" variant="outline" disabled={create.isPending || title.trim() === ''}>
                    <Plus aria-hidden />
                    {t('leads.tasks.add')}
                </Button>
            </form>
        </section>
    );
}
