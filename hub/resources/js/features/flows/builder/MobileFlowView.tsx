import { ArrowLeft, CircleAlert, CircleCheck, CornerDownRight, Monitor } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { metaOf } from '../catalog';
import { StatusBadge } from '../FlowsPage';
import type { FlowDetail, FlowOptions, Goal, Graph, GraphNode, Trigger } from '../types';
import { branchText, describeNode } from './describe';
import { CATEGORY_TONE } from './FlowNode';
import { outline, type OutlineItem } from './outline';

interface ListProps {
    graph: Graph;
    options: FlowOptions | undefined;
    trigger: Trigger;
    goal: Goal | null;
    issues: Record<string, string[]>;
    /** Algo debajo de un paso (la vista previa del mensaje en la galería de la biblioteca). */
    renderExtra?: ((node: GraphNode) => ReactNode) | undefined;
}

/** Vista del celular: el flujo en solo lectura como lista de pasos, con aviso de que se edita en la computadora. */
export function MobileFlowView({ flow, options }: { flow: FlowDetail; options: FlowOptions }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const issueCount = Object.values(flow.issues).reduce((acc, list) => acc + list.length, 0);
    return (
        <div className="igr:space-y-4 igr:px-4 igr:py-4">
            <div className="igr:flex igr:items-start igr:gap-2">
                <Button asChild variant="ghost" size="icon" className="igr:-ml-2 igr:shrink-0">
                    <Link to={`/w/${workspace.id}/flujos`} aria-label={t('flows.builder.back')}>
                        <ArrowLeft />
                    </Link>
                </Button>
                <div className="igr:min-w-0 igr:flex-1">
                    <h1 className="igr:text-[17px] igr:leading-snug igr:font-semibold">{flow.name}</h1>
                    <div className="igr:mt-1 igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-xs igr:text-muted-foreground">
                        <StatusBadge status={flow.status} />
                        {flow.current_version ? <span>{t('flows.builder.version', { version: flow.current_version.version })}</span> : null}
                        <span className={cn('igr:inline-flex igr:items-center igr:gap-1', issueCount > 0 ? 'igr:text-destructive' : 'igr:text-success')}>
                            {issueCount > 0 ? <CircleAlert className="igr:size-3.5" aria-hidden /> : <CircleCheck className="igr:size-3.5" aria-hidden />}
                            {issueCount > 0 ? t('flows.builder.issues', { count: issueCount }) : t('flows.builder.noIssues')}
                        </span>
                    </div>
                </div>
            </div>
            <div role="note" className="igr:flex igr:gap-3 igr:rounded-lg igr:border igr:bg-muted/50 igr:p-3 igr:text-[13px]">
                <Monitor className="igr:mt-0.5 igr:size-5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                <div>
                    <p className="igr:font-medium">{t('flows.canvas.mobileTitle')}</p>
                    <p className="igr:mt-0.5 igr:text-muted-foreground">{t('flows.canvas.mobileHelp')}</p>
                </div>
            </div>
            {flow.description ? <p className="igr:text-[13px] igr:text-muted-foreground">{flow.description}</p> : null}
            <FlowOutlineList graph={flow.graph} options={options} trigger={flow.trigger} goal={flow.goal} issues={flow.issues} />
        </div>
    );
}

/** Los pasos numerados; las ramas abren una sublista con su nombre. */
export function FlowOutlineList({ graph, options, trigger, goal, issues, renderExtra }: ListProps) {
    const { t } = useTranslation();
    const { items, orphans } = outline(graph);
    return (
        <div className="igr:space-y-4">
            <Items items={items} props={{ graph, options, trigger, goal, issues, renderExtra }} />
            {orphans.length > 0 ? (
                <section className="igr:space-y-2">
                    <h2 className="igr:text-[13px] igr:font-semibold">{t('flows.canvas.orphans')}</h2>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('flows.canvas.orphansHelp')}</p>
                    <Items items={orphans} props={{ graph, options, trigger, goal, issues, renderExtra }} />
                </section>
            ) : null}
        </div>
    );
}

function Items({ items, props }: { items: OutlineItem[]; props: ListProps }) {
    const { t } = useTranslation();
    return (
        <ol className="igr:space-y-2">
            {items.map((item) => {
                if (item.kind === 'goto') {
                    return (
                        <li key={`goto-${item.id}`} className="igr:flex igr:items-center igr:gap-1.5 igr:pl-1 igr:text-[12.5px] igr:text-muted-foreground">
                            <CornerDownRight className="igr:size-3.5" aria-hidden />
                            {t('flows.canvas.gotoStep', { number: item.number })}
                        </li>
                    );
                }
                const { step } = item;
                const meta = metaOf(step.node.type);
                const Icon = meta.icon;
                const info = describeNode(t, step.node, { options: props.options, trigger: props.trigger, goal: props.goal });
                const problems = props.issues[step.id] ?? [];
                return (
                    <li key={step.id}>
                        <div className={cn('igr:flex igr:gap-3 igr:rounded-lg igr:border igr:bg-card igr:p-3', problems.length > 0 && 'igr:border-destructive')}>
                            <span className={cn('igr:grid igr:size-8 igr:shrink-0 igr:place-items-center igr:rounded-lg', CATEGORY_TONE[meta.category])}>
                                <Icon className="igr:size-4" aria-hidden />
                            </span>
                            <div className="igr:min-w-0 igr:flex-1">
                                <p className="igr:text-[11.5px] igr:font-medium igr:text-muted-foreground">
                                    {t('flows.canvas.stepNumber', { number: step.number })} · {info.title}
                                </p>
                                <p className={cn('igr:text-[14px] igr:font-medium igr:leading-snug', info.incomplete && 'igr:text-warning')}>{info.summary}</p>
                                {info.details.length > 0 ? <p className="igr:mt-0.5 igr:text-[12px] igr:text-muted-foreground">{info.details.join(' · ')}</p> : null}
                                {problems.map((p) => (
                                    <p key={p} className="igr:mt-1.5 igr:flex igr:items-start igr:gap-1.5 igr:text-[12px] igr:text-destructive">
                                        <CircleAlert className="igr:mt-px igr:size-3.5 igr:shrink-0" aria-hidden />
                                        {p}
                                    </p>
                                ))}
                                {props.renderExtra?.(step.node)}
                            </div>
                        </div>
                        {step.branches.length > 0 ? (
                            <div className="igr:mt-2 igr:space-y-3 igr:border-l-2 igr:pl-3 igr:ml-4">
                                {step.branches.map((b) => (
                                    <section key={b.branch ?? 'next'} aria-label={branchText(t, step.node, b.branch)}>
                                        <h3 className="igr:mb-1.5 igr:text-[12px] igr:font-semibold igr:text-muted-foreground">{branchText(t, step.node, b.branch)}</h3>
                                        {b.items.length > 0 ? (
                                            <Items items={b.items} props={props} />
                                        ) : (
                                            <p className="igr:text-[12.5px] igr:text-muted-foreground">{t('flows.canvas.branchEnds')}</p>
                                        )}
                                    </section>
                                ))}
                            </div>
                        ) : null}
                    </li>
                );
            })}
        </ol>
    );
}
