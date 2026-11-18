import { Ban, Layers, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/cn';
import { appendTo, defaultCondition, depthOf, emptyGroup, MAX_DEPTH, type Path, removeAt, updateAt } from '../ast';
import type { ConditionType, Group } from '../types';
import { isGroup } from '../types';
import { ConditionEditor, TYPE_ICONS } from './ConditionEditor';
import { errorsFor, useBuilder } from './context';

const TYPES: ConditionType[] = ['attribute', 'purchase', 'event', 'cart', 'prediction', 'consent', 'segment'];

/** Condiciones que llegan en fases siguientes (se muestran, pero no se pueden usar todavía). */
const LATER: { key: string; phase: number }[] = [
    { key: 'messaging', phase: 2 },
    { key: 'loyalty', phase: 3 },
    { key: 'lead', phase: 5 },
];

/**
 * Grupo con «todas» (y) o «cualquiera» (o), que se puede negar y anidar
 * hasta MAX_DEPTH niveles. Cada nodo edita la raíz por su ruta.
 */
export function GroupEditor({ group, path, root, setRoot }: { group: Group; path: Path; root: Group; setRoot: (g: Group) => void }) {
    const { t } = useTranslation();
    const { errors, readOnly } = useBuilder();
    const isRoot = path.length === 0;
    const canNest = depthOf(path) < MAX_DEPTH;
    const messages = errorsFor(errors, path);
    const setGroup = (next: Group) => setRoot(updateAt(root, path, () => next));

    return (
        <div className={cn('igr:space-y-2', !isRoot && 'igr:rounded-lg igr:border igr:border-dashed igr:bg-muted/30 igr:p-2.5', !isRoot && group.negate && 'igr:border-destructive/40 igr:bg-destructive/5')}>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px]">
                {group.negate ? (
                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:font-medium igr:text-destructive">
                        <Ban className="igr:size-3.5" />
                        {t('segments.builder.exclude')}
                    </span>
                ) : null}
                <span className="igr:text-muted-foreground">{isRoot ? t('segments.builder.rootLead') : t('segments.builder.groupLead')}</span>
                <OpToggle value={group.op} disabled={readOnly} onChange={(op) => setGroup({ ...group, op })} />
                <span className="igr:text-muted-foreground">{t('segments.builder.ofConditions')}</span>
                {!isRoot && !readOnly ? (
                    <span className="igr:ml-auto igr:flex igr:items-center igr:gap-1">
                        <Button variant="ghost" size="sm" aria-pressed={group.negate === true} onClick={() => setGroup(group.negate ? { op: group.op, conditions: group.conditions } : { ...group, negate: true })}>
                            <Ban />
                            {group.negate ? t('segments.builder.include') : t('segments.builder.excludeAction')}
                        </Button>
                        <Button variant="ghost" size="icon" className="igr:size-7" onClick={() => setRoot(removeAt(root, path))} aria-label={t('segments.builder.removeGroup')}>
                            <Trash2 />
                        </Button>
                    </span>
                ) : null}
            </div>

            {group.conditions.map((child, i) => {
                const childPath = [...path, i];
                return (
                    <div key={i} className="igr:space-y-2">
                        {i > 0 ? (
                            <div className="igr:flex igr:items-center igr:gap-2">
                                <span className="igr:rounded igr:bg-secondary igr:px-1.5 igr:py-0.5 igr:text-[10px] igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t(`segments.join.${group.op}`)}</span>
                                <span className="igr:h-px igr:flex-1 igr:bg-border" />
                            </div>
                        ) : null}
                        {isGroup(child) ? (
                            <GroupEditor group={child} path={childPath} root={root} setRoot={setRoot} />
                        ) : (
                            <ConditionEditor condition={child} path={childPath} onChange={(c) => setRoot(updateAt(root, childPath, () => c))} onRemove={() => setRoot(removeAt(root, childPath))} />
                        )}
                    </div>
                );
            })}

            {messages.length > 0 ? (
                <ul className="igr:text-xs igr:text-destructive">
                    {messages.map((m) => (
                        <li key={m}>{m}</li>
                    ))}
                </ul>
            ) : null}

            {readOnly ? null : (
                <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                    <AddConditionMenu onAdd={(type) => setRoot(appendTo(root, path, defaultCondition(type)))} />
                    {canNest ? (
                        <Button variant="ghost" size="sm" className="igr:text-muted-foreground" onClick={() => setRoot(appendTo(root, path, { ...emptyGroup(group.op === 'and' ? 'or' : 'and'), conditions: [defaultCondition('attribute')] }))}>
                            <Layers />
                            {t('segments.builder.addGroup')}
                        </Button>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function OpToggle({ value, onChange, disabled }: { value: Group['op']; onChange: (op: Group['op']) => void; disabled: boolean }) {
    const { t } = useTranslation();
    return (
        <span role="radiogroup" aria-label={t('segments.builder.combine')} className="igr:inline-flex igr:rounded-md igr:border igr:p-0.5">
            {(['and', 'or'] as const).map((op) => (
                <button
                    key={op}
                    type="button"
                    role="radio"
                    aria-checked={value === op}
                    disabled={disabled}
                    onClick={() => onChange(op)}
                    className={cn(
                        'igr:rounded igr:px-2 igr:py-0.5 igr:text-xs igr:font-medium igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring/40',
                        value === op ? 'igr:bg-primary igr:text-primary-foreground' : 'igr:text-muted-foreground igr:hover:text-foreground',
                    )}
                >
                    {t(`segments.builder.op_${op}`)}
                </button>
            ))}
        </span>
    );
}

export function AddConditionMenu({ onAdd, variant = 'outline' }: { onAdd: (type: ConditionType) => void; variant?: 'outline' | 'default' }) {
    const { t } = useTranslation();
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant={variant} size="sm">
                    <Plus />
                    {t('segments.builder.addCondition')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="igr:w-72">
                {TYPES.map((type) => {
                    const Icon = TYPE_ICONS[type];
                    return (
                        <DropdownMenuItem key={type} onSelect={() => onAdd(type)}>
                            <Icon />
                            <span className="igr:flex igr:flex-col">
                                <span>{t(`segments.types.${type}`)}</span>
                                <span className="igr:text-xs igr:text-muted-foreground">{t(`segments.typeHelp.${type}`)}</span>
                            </span>
                        </DropdownMenuItem>
                    );
                })}
                <DropdownMenuSeparator />
                <DropdownMenuLabel>{t('segments.builder.comingSoon')}</DropdownMenuLabel>
                {LATER.map((l) => (
                    <DropdownMenuItem key={l.key} disabled>
                        <span className="igr:flex-1">{t(`segments.types.${l.key}`)}</span>
                        <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.phase', { phase: l.phase })}</span>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
