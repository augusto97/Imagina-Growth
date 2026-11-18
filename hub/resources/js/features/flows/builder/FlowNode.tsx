import { Handle, type NodeProps, Position } from '@xyflow/react';
import { CircleAlert, Lock } from 'lucide-react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import { branchesOf, branchLabel, metaOf, summarize } from '../catalog';
import { type FlowCanvasNode, handleOf } from '../graph';
import { useCanvas } from './context';

const CATEGORY_RING: Record<string, string> = {
    trigger: 'igr:border-primary/60',
    wait: 'igr:border-border',
    logic: 'igr:border-border',
    action: 'igr:border-border',
    control: 'igr:border-border',
};

/** Paso del flujo en el lienzo. Las salidas van abajo, una por rama, con su nombre. */
export const FlowNode = memo(function FlowNode({ data, selected }: NodeProps<FlowCanvasNode>) {
    const { t } = useTranslation();
    const { options, trigger, issues, stats, locale } = useCanvas();
    const node = data.node;
    const meta = metaOf(node.type);
    const Icon = meta.icon;
    const branches = branchesOf(node);
    const problems = issues[node.id] ?? [];
    const s = stats?.[node.id];
    // Envíos: el estado real de los mensajes si ya hay, si no lo que registró el paso.
    const sent = s ? (s.messages.sent ?? s.outcomes.sent ?? 0) : 0;
    const notSent = s ? (s.outcomes.denied ?? 0) + (s.outcomes.failed ?? 0) : 0;

    return (
        <div
            className={cn(
                'igr:relative igr:w-64 igr:rounded-lg igr:border igr:bg-card igr:text-card-foreground igr:shadow-sm igr:transition-shadow',
                CATEGORY_RING[meta.category],
                selected && 'igr:ring-2 igr:ring-ring igr:ring-offset-1 igr:ring-offset-background',
                problems.length > 0 && 'igr:border-destructive/60',
            )}
        >
            {node.type !== 'trigger' ? (
                <Handle type="target" id="in" position={Position.Top} style={{ width: 10, height: 10, background: 'var(--muted-foreground)', border: '2px solid var(--background)' }} />
            ) : null}
            <div className="igr:flex igr:items-start igr:gap-2.5 igr:p-3">
                <span className={cn('igr:mt-0.5 igr:grid igr:size-7 igr:shrink-0 igr:place-items-center igr:rounded-md', node.type === 'trigger' ? 'igr:bg-primary igr:text-primary-foreground' : 'igr:bg-secondary igr:text-secondary-foreground')}>
                    <Icon className="igr:size-3.5" aria-hidden />
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-[12.5px] igr:font-medium">
                        <span className="igr:truncate">{t(`flows.nodes.${node.type}`, { defaultValue: node.type })}</span>
                        {meta.phase ? <Lock className="igr:size-3 igr:text-muted-foreground" aria-label={t('flows.config.laterPhase', { phase: meta.phase })} /> : null}
                    </div>
                    <p className="igr:mt-0.5 igr:line-clamp-2 igr:text-[11.5px] igr:leading-snug igr:text-muted-foreground">{summarize(t, node, options, trigger)}</p>
                    {problems.length > 0 ? (
                        <p className="igr:mt-1.5 igr:flex igr:items-start igr:gap-1 igr:text-[11px] igr:text-destructive">
                            <CircleAlert className="igr:mt-px igr:size-3 igr:shrink-0" aria-hidden />
                            <span className="igr:line-clamp-2">{problems[0]}</span>
                        </p>
                    ) : null}
                    {s ? (
                        <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-x-3 igr:gap-y-0.5 igr:border-t igr:pt-1.5 igr:text-[11px] igr:text-muted-foreground">
                            <Stat label={t('flows.analytics.node.reached')} value={formatNumber(s.reached, locale)} />
                            {sent ? <Stat label={t('flows.analytics.node.sent')} value={formatNumber(sent, locale)} /> : null}
                            {notSent ? <Stat label={t('flows.analytics.node.notSent')} value={formatNumber(notSent, locale)} /> : null}
                            {s.messages.clicked ? <Stat label={t('flows.analytics.node.clicked')} value={formatNumber(s.messages.clicked, locale)} /> : null}
                            <Stat label={t('flows.analytics.node.converted')} value={formatNumber(s.converted, locale)} />
                        </div>
                    ) : null}
                </div>
            </div>
            {branches.length > 1 || (branches.length === 1 && branches[0] !== null) ? (
                <div className="igr:flex igr:border-t igr:px-1 igr:pb-2.5 igr:pt-1">
                    {branches.map((branch) => (
                        <span key={handleOf(branch)} className="igr:min-w-0 igr:flex-1 igr:truncate igr:text-center igr:text-[10.5px] igr:text-muted-foreground">
                            {branchLabel(t, node.type, branch)}
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
                    style={{ left: `${((i + 0.5) / branches.length) * 100}%`, width: 10, height: 10, background: 'var(--primary)', border: '2px solid var(--background)' }}
                    aria-label={branchLabel(t, node.type, branch)}
                />
            ))}
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
