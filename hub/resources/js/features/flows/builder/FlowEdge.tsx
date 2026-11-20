import { BaseEdge, type Edge, EdgeLabelRenderer, type EdgeProps, getSmoothStepPath, useNodesData } from '@xyflow/react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { branchOf, type FlowCanvasNode } from '../graph';
import { AddStepMenu } from './AddStepMenu';
import { useCanvas } from './context';
import { edgeLabel } from './describe';

/**
 * Conexión entre dos pasos: la rama con su nombre («Sí», «No compró»…) cerca del paso de origen
 * y un «+» para poner un paso en medio. Sin nombre, el «+» va en el centro.
 */
export const FlowEdge = memo(function FlowEdge({
    id,
    source,
    target,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    sourceHandleId,
    markerEnd,
    selected,
}: EdgeProps<Edge>) {
    const { t } = useTranslation();
    const { readOnly, onInsert } = useCanvas();
    const from = useNodesData<FlowCanvasNode>(source);
    const to = useNodesData<FlowCanvasNode>(target);
    const [path, midX, midY] = getSmoothStepPath({ sourceX, sourceY, sourcePosition, targetX, targetY, targetPosition, borderRadius: 12, offset: 20 });
    const label = from ? edgeLabel(t, from.data.node, branchOf(sourceHandleId)) : null;
    // Hacia abajo, la etiqueta va sobre el primer tramo vertical y el «+» sobre el último.
    const gap = targetY - sourceY;
    const downward = gap > 0;
    const labelPos = downward ? { x: sourceX, y: sourceY + 14 } : { x: midX, y: midY - 14 };
    // Sin nombre: el «+» al centro. Con nombre: cerca del destino, si cabe sin pisar la etiqueta ni la flecha.
    const plusPos =
        label === null ? (gap < 0 || gap >= 30 ? { x: midX, y: midY } : null) : !downward ? { x: midX, y: midY + 14 } : gap >= 60 ? { x: targetX, y: targetY - 22 } : null;
    const stepName = (n: Pick<FlowCanvasNode, 'data'> | null) => (n ? t(`flows.nodes.${n.data.node.type}`, { defaultValue: n.data.node.type }) : '');

    return (
        <>
            <BaseEdge id={id} path={path} markerEnd={markerEnd} interactionWidth={18} style={{ strokeWidth: selected ? 2.25 : 1.5 }} />
            <EdgeLabelRenderer>
                {label !== null ? (
                    <div
                        className={cn(
                            'igr:absolute igr:max-w-40 igr:truncate igr:rounded-full igr:border igr:bg-card igr:px-2 igr:py-0.5 igr:text-[11px] igr:font-medium igr:leading-tight igr:shadow-xs',
                            selected ? 'igr:border-primary igr:text-primary' : 'igr:text-muted-foreground',
                        )}
                        style={{ transform: `translate(-50%, -50%) translate(${labelPos.x}px, ${labelPos.y}px)`, pointerEvents: 'none' }}
                    >
                        {label}
                    </div>
                ) : null}
                {!readOnly && onInsert && plusPos !== null ? (
                    <div className="nodrag nopan igr:absolute" style={{ transform: `translate(-50%, -50%) translate(${plusPos.x}px, ${plusPos.y}px)`, pointerEvents: 'all' }}>
                        <AddStepMenu label={t('flows.canvas.insertBetween', { from: stepName(from), to: stepName(to) })} onPick={(type) => onInsert(id, type)} />
                    </div>
                ) : null}
            </EdgeLabelRenderer>
        </>
    );
});
