import { Handle, type NodeProps, Position } from '@xyflow/react';
import { CircleAlert, Lock } from 'lucide-react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import { branchesOf, type Category, metaOf } from '../catalog';
import { type FlowCanvasNode, handleOf } from '../graph';
import { AddStepMenu } from './AddStepMenu';
import { useCanvas } from './context';
import { branchText, describeNode } from './describe';

/** Color del ícono según el tipo de paso: esperas, lógica, acciones y control se distinguen de un vistazo. */
export const CATEGORY_TONE: Record<Category, string> = {
    trigger: 'igr:bg-primary igr:text-primary-foreground',
    wait: 'igr:bg-warning/15 igr:text-warning',
    logic: 'igr:bg-primary/10 igr:text-primary',
    action: 'igr:bg-accent igr:text-accent-foreground',
    control: 'igr:bg-muted igr:text-muted-foreground',
};

const HANDLE = { width: 12, height: 12, border: '2px solid var(--background)' };

/**
 * Paso del flujo en el lienzo: qué es, qué hace con su configuración, lo que le falta y, abajo,
 * una salida por rama con su nombre. Las salidas libres ofrecen «+» para agregar el siguiente paso.
 */
export const FlowNode = memo(function FlowNode({ id, data, selected }: NodeProps<FlowCanvasNode>) {
    const { t } = useTranslation();
    const { options, trigger, goal, issues, stats, locale, readOnly, connected, onAddAfter } = useCanvas();
    const node = data.node;
    const meta = metaOf(node.type);
    const Icon = meta.icon;
    const info = describeNode(t, node, { options, trigger, goal });
    const branches = branchesOf(node);
    const problems = issues[id] ?? [];
    const s = stats?.[id];
    // Envíos: el estado real de los mensajes si ya hay, si no lo que registró el paso.
    const sent = s ? (s.messages.sent ?? s.outcomes.sent ?? 0) : 0;
    const notSent = s ? (s.outcomes.denied ?? 0) + (s.outcomes.failed ?? 0) : 0;
    const used = connected?.[id] ?? [];
    // Un paso sin ninguna salida conectada es el final de un camino: su «+» se ve siempre.
    const isEnd = used.length === 0;
    const labeled = branches.length > 1 || (branches.length === 1 && branches[0] !== null);
    const free = branches.filter((b) => !used.includes(handleOf(b)));

    return (
        <div
            className={cn(
                // React Flow quita el contorno del paso con foco: el anillo se dibuja aquí (navegación con Tab).
                'igr:group igr:relative igr:w-[272px] igr:rounded-xl igr:border igr:bg-card igr:text-card-foreground igr:shadow-sm igr:transition-shadow igr:hover:shadow-md igr:in-focus-visible:ring-2 igr:in-focus-visible:ring-ring igr:in-focus-visible:ring-offset-2 igr:in-focus-visible:ring-offset-background',
                node.type === 'trigger' && 'igr:border-primary/50',
                problems.length > 0 && 'igr:border-destructive',
                selected && 'igr:border-primary igr:ring-2 igr:ring-primary/35',
            )}
        >
            {node.type !== 'trigger' ? <Handle type="target" id="in" position={Position.Top} style={{ ...HANDLE, background: 'var(--muted-foreground)' }} /> : null}
            <div className="igr:flex igr:items-start igr:gap-2.5 igr:p-3">
                <span className={cn('igr:mt-0.5 igr:grid igr:size-8 igr:shrink-0 igr:place-items-center igr:rounded-lg', CATEGORY_TONE[meta.category])}>
                    <Icon className="igr:size-4" aria-hidden />
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-[11.5px] igr:font-medium igr:text-muted-foreground">
                        <span className="igr:truncate">{info.title}</span>
                        {meta.phase ? <Lock className="igr:size-3" aria-label={t('flows.config.laterPhase', { phase: meta.phase })} /> : null}
                    </div>
                    <p className={cn('igr:mt-0.5 igr:line-clamp-2 igr:text-[13px] igr:font-medium igr:leading-snug', info.incomplete ? 'igr:text-warning' : 'igr:text-foreground')}>
                        {info.summary}
                    </p>
                    {info.details.length > 0 ? <p className="igr:mt-0.5 igr:line-clamp-1 igr:text-[11.5px] igr:text-muted-foreground">{info.details.join(' · ')}</p> : null}
                </div>
            </div>
            {problems.length > 0 ? (
                <p className="igr:mx-3 igr:-mt-1 igr:mb-3 igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:bg-destructive/10 igr:px-2 igr:py-1.5 igr:text-[11.5px] igr:leading-snug igr:text-destructive">
                    <CircleAlert className="igr:mt-px igr:size-3.5 igr:shrink-0" aria-hidden />
                    <span className="igr:line-clamp-3">
                        <span className="igr:sr-only">{t('flows.canvas.issueLabel')}: </span>
                        {problems[0]}
                        {problems.length > 1 ? ` ${t('flows.canvas.moreIssues', { count: problems.length - 1 })}` : ''}
                    </span>
                </p>
            ) : null}
            {s ? (
                <div className="igr:mx-3 igr:mb-2.5 igr:flex igr:flex-wrap igr:gap-x-3 igr:gap-y-0.5 igr:border-t igr:pt-1.5 igr:text-[11px] igr:text-muted-foreground">
                    <Stat label={t('flows.analytics.node.reached')} value={formatNumber(s.reached, locale)} />
                    {sent ? <Stat label={t('flows.analytics.node.sent')} value={formatNumber(sent, locale)} /> : null}
                    {notSent ? <Stat label={t('flows.analytics.node.notSent')} value={formatNumber(notSent, locale)} /> : null}
                    {s.messages.clicked ? <Stat label={t('flows.analytics.node.clicked')} value={formatNumber(s.messages.clicked, locale)} /> : null}
                    <Stat label={t('flows.analytics.node.converted')} value={formatNumber(s.converted, locale)} />
                </div>
            ) : null}
            {labeled && free.length > 0 ? (
                // Nombre de cada salida libre sobre su punto; las conectadas lo llevan en su conexión.
                <div className="igr:flex igr:border-t igr:px-1 igr:pt-1 igr:pb-2.5">
                    {branches.map((branch) => (
                        <span
                            key={handleOf(branch)}
                            className="igr:min-w-0 igr:flex-1 igr:truncate igr:px-1 igr:text-center igr:text-[11px] igr:font-medium igr:text-muted-foreground"
                        >
                            {used.includes(handleOf(branch)) ? '' : branchText(t, node, branch)}
                        </span>
                    ))}
                </div>
            ) : null}
            {branches.map((branch, i) => (
                <Handle
                    key={handleOf(branch)}
                    type="source"
                    id={handleOf(branch)}
                    position={Position.Bottom}
                    style={{ ...HANDLE, left: `${((i + 0.5) / branches.length) * 100}%`, background: 'var(--primary)' }}
                    aria-label={branchText(t, node, branch)}
                />
            ))}
            {!readOnly && onAddAfter
                ? branches.map((branch, i) =>
                      used.includes(handleOf(branch)) ? null : (
                          <div
                              key={`add-${handleOf(branch)}`}
                              className={cn(
                                  'igr:absolute igr:top-full igr:z-10 igr:-translate-x-1/2 igr:translate-y-3 igr:transition-opacity igr:focus-within:opacity-100',
                                  isEnd || selected ? 'igr:opacity-100' : 'igr:opacity-0 igr:group-hover:opacity-100',
                              )}
                              style={{ left: `${((i + 0.5) / branches.length) * 100}%` }}
                          >
                              <AddStepMenu
                                  label={
                                      labeled
                                          ? t('flows.canvas.addAfterBranch', { step: info.title, branch: branchText(t, node, branch) })
                                          : t('flows.canvas.addAfter', { step: info.title })
                                  }
                                  onPick={(type) => onAddAfter(id, branch, type)}
                              />
                          </div>
                      ),
                  )
                : null}
        </div>
    );
});

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <span className="igr:flex igr:gap-1">
            <span className="igr:font-medium igr:tabular-nums igr:text-foreground">{value}</span>
            {label}
        </span>
    );
}
