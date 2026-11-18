import '@xyflow/react/dist/style.css';
import { Background, type Connection, Controls, type Edge, MarkerType, MiniMap, ReactFlow, type ReactFlowInstance, ReactFlowProvider, useEdgesState, useNodesState } from '@xyflow/react';
import { Archive, ArrowLeft, BarChart3, CircleAlert, CircleCheck, FlaskConical, History, Pause, Play, Route, Save, Send } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Kbd } from '@/components/ui/kbd';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useUi } from '@/stores/ui';
import { useFlow, useFlowAnalytics, useFlowOptions, useFlowStatus, usePublishFlow, useRestoreVersion, useUpdateFlow } from '../api';
import { metaOf, PALETTE } from '../catalog';
import { branchOf, edgeId, type FlowCanvasNode, freeBranch, fromCanvas, handleOf, nextNodeId, pruneEdges, toCanvas } from '../graph';
import { StatusBadge } from '../FlowsPage';
import type { FlowDetail, FlowOptions, GraphNode } from '../types';
import { CanvasContext } from './context';
import { FlowNode } from './FlowNode';
import { NodePanel } from './NodePanel';
import { ResultsBar } from './ResultsBar';
import { RunsDialog } from './RunsDialog';
import { type FlowSettings, SettingsPanel } from './SettingsPanel';
import { TestDialog } from './TestDialog';

const NODE_TYPES = { flow: FlowNode };
const NODE_WIDTH = 256;
const NODE_HEIGHT = 90;
/** Por debajo de este zoom el texto de los pasos no se lee: se muestra el comienzo del flujo y se desplaza. */
const READABLE_ZOOM = 0.75;
const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

export function FlowBuilderPage() {
    const { flowId = '' } = useParams();
    const { workspace } = useWorkspace();
    const flow = useFlow(workspace.id, flowId);
    const options = useFlowOptions(workspace.id);

    if (flow.isPending || options.isPending) {
        return <Skeleton className="igr:h-[70vh]" />;
    }
    if (flow.isError || options.isError) {
        return <ErrorState error={flow.error ?? options.error} onRetry={() => void (flow.isError ? flow.refetch() : options.refetch())} />;
    }
    return (
        <ReactFlowProvider>
            <Builder key={flow.data.id} flow={flow.data} options={options.data} />
        </ReactFlowProvider>
    );
}

function settingsOf(flow: FlowDetail): FlowSettings {
    return {
        name: flow.name,
        description: flow.description ?? '',
        trigger: flow.trigger,
        goal: flow.goal,
        reentry_policy: flow.reentry_policy,
        exclusions: flow.exclusions,
        holdout_pct: flow.holdout_pct,
    };
}

function Builder({ flow, options }: { flow: FlowDetail; options: FlowOptions }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const readOnly = !canManage || flow.status === 'archived';
    const theme = useUi((s) => s.theme);
    const dark = theme === 'dark' || (theme === 'system' && typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    const initial = useMemo(() => toCanvas(flow.graph), [flow.graph]);
    const [nodes, setNodes, onNodesChange] = useNodesState<FlowCanvasNode>(initial.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>(initial.edges);
    const [settings, setSettings] = useState<FlowSettings>(() => settingsOf(flow));
    const [selected, setSelected] = useState<string | null>('trigger');
    const [dirty, setDirty] = useState(false);
    const [issues, setIssues] = useState<Record<string, string[]>>(flow.issues);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [showResults, setShowResults] = useState(false);
    const [days, setDays] = useState(30);
    const [dialog, setDialog] = useState<'test' | 'runs' | 'publish' | 'archive' | null>(null);
    const [migrate, setMigrate] = useState(false);

    const canvasRef = useRef<HTMLDivElement>(null);
    // Vista inicial: todo el flujo si cabe legible; si no, el comienzo arriba y centrado.
    const onInit = useCallback(
        (rf: ReactFlowInstance<FlowCanvasNode, Edge>) => {
            const el = canvasRef.current;
            if (!el || initial.nodes.length === 0) {
                return;
            }
            const xs = initial.nodes.map((n) => n.position.x);
            const ys = initial.nodes.map((n) => n.position.y);
            const minX = Math.min(...xs);
            const maxX = Math.max(...xs) + NODE_WIDTH;
            const minY = Math.min(...ys);
            const maxY = Math.max(...ys) + NODE_HEIGHT;
            const fit = Math.min(1, (el.clientWidth - 64) / (maxX - minX), (el.clientHeight - 64) / (maxY - minY));
            const zoom = Math.max(fit, READABLE_ZOOM);
            const x = el.clientWidth / 2 - ((minX + maxX) / 2) * zoom;
            const y = fit >= READABLE_ZOOM ? el.clientHeight / 2 - ((minY + maxY) / 2) * zoom : 32 - minY * zoom;
            void rf.setViewport({ x, y, zoom });
        },
        [initial.nodes],
    );

    const update = useUpdateFlow(workspace.id, flow.id);
    const publish = usePublishFlow(workspace.id, flow.id);
    const status = useFlowStatus(workspace.id, flow.id);
    const restore = useRestoreVersion(workspace.id, flow.id);
    const analytics = useFlowAnalytics(workspace.id, flow.id, days, showResults);

    // Cada cambio del lienzo marca el borrador como modificado.
    const markDirty = useCallback(() => setDirty(true), []);
    const graphNodes = useMemo(() => nodes.map((n) => n.data.node), [nodes]);

    const applyFlow = useCallback(
        (next: FlowDetail) => {
            const canvas = toCanvas(next.graph);
            setNodes(canvas.nodes);
            setEdges(canvas.edges);
            setSettings(settingsOf(next));
            setIssues(next.issues);
            setDirty(false);
        },
        [setNodes, setEdges],
    );

    const handleError = useCallback(
        (error: unknown, title: string) => {
            if (isApiError(error, 'validation')) {
                const nodeIssues: Record<string, string[]> = {};
                const rest: Record<string, string> = {};
                for (const [key, message] of Object.entries(error.fieldErrors)) {
                    const match = /^graph\.nodes\.(.+)$/.exec(key);
                    if (match?.[1] !== undefined) {
                        nodeIssues[match[1]] = [message];
                    } else if (key === 'graph') {
                        nodeIssues[''] = [message];
                    } else {
                        rest[key] = message;
                    }
                }
                if (Object.keys(nodeIssues).length > 0) {
                    setIssues(nodeIssues);
                }
                setFieldErrors(rest);
                if (Object.keys(rest).length > 0) {
                    setSelected('trigger');
                }
                toast.error(title, error.message);
            } else {
                toast.error(title, isApiError(error) ? error.message : undefined);
            }
        },
        [],
    );

    const save = useCallback(
        (onSaved?: () => void) => {
            if (readOnly) {
                return;
            }
            setFieldErrors({});
            update.mutate(
                {
                    name: settings.name.trim(),
                    description: settings.description.trim() === '' ? null : settings.description.trim(),
                    trigger: settings.trigger,
                    goal: settings.goal,
                    reentry_policy: settings.reentry_policy,
                    exclusions: settings.exclusions,
                    holdout_pct: settings.holdout_pct,
                    graph: fromCanvas(nodes, edges),
                },
                {
                    onSuccess: (next) => {
                        setIssues(next.issues);
                        setDirty(false);
                        if (onSaved) {
                            onSaved();
                        } else {
                            toast.success(t('flows.builder.saved'));
                        }
                    },
                    onError: (e) => handleError(e, t('flows.builder.saveFailed')),
                },
            );
        },
        [readOnly, update, settings, nodes, edges, handleError, t],
    );

    const doPublish = () => {
        const run = () =>
            publish.mutate(
                { migrate },
                {
                    onSuccess: (next) => {
                        applyFlow(next);
                        setDialog(null);
                        toast.success(t('flows.builder.published', { version: next.current_version?.version ?? '' }), next.migrated ? t('flows.builder.migrated', { count: next.migrated }) : undefined);
                    },
                    onError: (e) => {
                        setDialog(null);
                        handleError(e, t('flows.builder.publishFailed'));
                    },
                },
            );
        if (dirty || !flow.has_draft) {
            save(run);
        } else {
            run();
        }
    };

    const changeStatus = (action: 'activate' | 'pause' | 'archive') => {
        status.mutate(action, {
            onSuccess: () => {
                toast.success(t(action === 'activate' ? 'flows.builder.activated' : action === 'pause' ? 'flows.builder.paused' : 'flows.builder.archived'));
                setDialog(null);
            },
            onError: (e) => handleError(e, t('flows.builder.saveFailed')),
        });
    };

    // ⌘S / Ctrl+S guarda.
    const saveRef = useRef(save);
    useEffect(() => {
        saveRef.current = save;
    });
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                saveRef.current();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);
    // Avisa al cerrar la pestaña con cambios sin guardar.
    useEffect(() => {
        if (!dirty) {
            return;
        }
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [dirty]);

    const onConnect = useCallback(
        (c: Connection) => {
            if (readOnly || c.source === c.target) {
                return;
            }
            const handle = handleOf(c.sourceHandle);
            const id = edgeId(c.source, branchOf(handle));
            // Cada salida va a un solo paso: la nueva conexión reemplaza a la anterior.
            setEdges((eds) => [...eds.filter((e) => !(e.source === c.source && (e.sourceHandle ?? 'next') === handle)), { id, source: c.source, target: c.target, sourceHandle: handle, targetHandle: 'in' }]);
            markDirty();
        },
        [readOnly, setEdges, markDirty],
    );

    const addNode = (type: string) => {
        if (readOnly) {
            return;
        }
        const from = nodes.find((n) => n.id === selected) ?? nodes.at(-1);
        const id = nextNodeId(nodes);
        const node: GraphNode = { id, type, config: metaOf(type).defaults() };
        // Debajo del paso elegido; si ahí ya hay otro, se corre a la derecha hasta encontrar espacio.
        const position = from ? { x: from.position.x, y: from.position.y + 170 } : { x: 0, y: 0 };
        const taken = (x: number, y: number) => nodes.some((n) => Math.abs(n.position.x - x) < NODE_WIDTH + 24 && Math.abs(n.position.y - y) < NODE_HEIGHT + 40);
        while (taken(position.x, position.y)) {
            position.x += NODE_WIDTH + 44;
        }
        setNodes((ns) => [...ns.map((n) => ({ ...n, selected: false })), { id, type: 'flow', position, data: { node }, selected: true }]);
        if (from) {
            const branch = freeBranch(from.data.node, edges);
            if (branch !== undefined) {
                setEdges((eds) => [...eds, { id: edgeId(from.id, branch), source: from.id, target: id, sourceHandle: handleOf(branch), targetHandle: 'in' }]);
            }
        }
        setSelected(id);
        markDirty();
    };

    const updateConfig = (id: string, config: Record<string, unknown>) => {
        setNodes((ns) => {
            const next = ns.map((n) => (n.id === id ? { ...n, data: { node: { ...n.data.node, config } } } : n));
            setEdges((eds) => pruneEdges(next, eds));
            return next;
        });
        markDirty();
    };

    const deleteNode = (id: string) => {
        if (id === 'trigger') {
            return;
        }
        setNodes((ns) => ns.filter((n) => n.id !== id));
        setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
        setSelected('trigger');
        markDirty();
    };

    const selectedNode = graphNodes.find((n) => n.id === selected) ?? null;
    const issueCount = Object.values(issues).reduce((acc, list) => acc + list.length, 0);
    const ctx = useMemo(
        () => ({ options, trigger: settings.trigger, issues, stats: showResults ? (analytics.data?.nodes ?? null) : null, readOnly, locale: workspace.locale }),
        [options, settings.trigger, issues, showResults, analytics.data, readOnly, workspace.locale],
    );

    return (
        <div className="igr:-m-4 igr:flex igr:h-[calc(100vh-3.5rem)] igr:flex-col igr:md:-m-6">
            {/* Barra superior */}
            <header className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-b igr:bg-background igr:px-3 igr:py-2">
                <Button asChild variant="ghost" size="icon" className="igr:size-8">
                    <Link to={`/w/${workspace.id}/flujos`} aria-label={t('flows.builder.back')}>
                        <ArrowLeft />
                    </Link>
                </Button>
                <h1 className="igr:max-w-[28ch] igr:truncate igr:text-[15px] igr:font-semibold">{settings.name}</h1>
                <StatusBadge status={flow.status} />
                <span className="igr:text-xs igr:text-muted-foreground">
                    {flow.has_draft || dirty ? t('flows.builder.draft', { version: flow.draft_version ?? (flow.current_version?.version ?? 0) + 1 }) : flow.current_version ? t('flows.builder.version', { version: flow.current_version.version }) : null}
                </span>
                {dirty ? <Badge variant="warning">{t('flows.builder.unsaved')}</Badge> : null}
                <span className={cn('igr:inline-flex igr:items-center igr:gap-1 igr:text-xs', issueCount > 0 ? 'igr:text-destructive' : 'igr:text-muted-foreground')}>
                    {issueCount > 0 ? <CircleAlert className="igr:size-3.5" aria-hidden /> : <CircleCheck className="igr:size-3.5" aria-hidden />}
                    {issueCount > 0 ? t('flows.builder.issues', { count: issueCount }) : t('flows.builder.noIssues')}
                </span>
                <div className="igr:ml-auto igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                    <Button variant={showResults ? 'secondary' : 'ghost'} size="sm" aria-pressed={showResults} onClick={() => setShowResults((v) => !v)}>
                        <BarChart3 />
                        {t('flows.builder.results')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setDialog('runs')}>
                        <Route />
                        {t('flows.builder.runs')}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="sm">
                                <History />
                                {t('flows.builder.versions')}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="igr:w-72">
                            <DropdownMenuLabel>{t('flows.builder.versions')}</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {flow.versions.length === 0 ? <div className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">—</div> : null}
                            {flow.versions.map((v) => (
                                <DropdownMenuItem
                                    key={v.id}
                                    disabled={readOnly}
                                    onSelect={() =>
                                        restore.mutate(v.id, {
                                            onSuccess: (next) => {
                                                applyFlow(next);
                                                toast.success(t('flows.builder.restored', { version: v.version }));
                                            },
                                        })
                                    }
                                >
                                    <span className="igr:flex-1">
                                        {t('flows.builder.version', { version: v.version })}
                                        <span className="igr:block igr:text-[11px] igr:text-muted-foreground">{formatDateTime(v.published_at, workspace.locale, workspace.timezone)}</span>
                                    </span>
                                    <span className="igr:text-[11px] igr:text-muted-foreground">{t('flows.builder.restore')}</span>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {canManage ? (
                        <>
                            <Button variant="outline" size="sm" onClick={() => setDialog('test')}>
                                <FlaskConical />
                                {t('flows.builder.test')}
                            </Button>
                            <Button variant="outline" size="sm" disabled={readOnly || !dirty} loading={update.isPending && dialog === null} onClick={() => save()}>
                                <Save />
                                {t('flows.builder.save')}
                                <Kbd className="igr:ml-1">{IS_MAC ? '⌘S' : 'Ctrl S'}</Kbd>
                            </Button>
                            <Button size="sm" disabled={readOnly || (!dirty && !flow.has_draft)} onClick={() => setDialog('publish')}>
                                <Send />
                                {t('flows.builder.publish')}
                            </Button>
                            {flow.status === 'active' ? (
                                <Button variant="outline" size="sm" loading={status.isPending} onClick={() => changeStatus('pause')}>
                                    <Pause />
                                    {t('flows.builder.pause')}
                                </Button>
                            ) : flow.status !== 'archived' ? (
                                <Tooltip content={flow.current_version === null ? t('flows.builder.publishHelp') : t('flows.builder.activate')}>
                                    <span>
                                        <Button variant="outline" size="sm" disabled={flow.current_version === null || dirty} loading={status.isPending} onClick={() => changeStatus('activate')}>
                                            <Play />
                                            {t('flows.builder.activate')}
                                        </Button>
                                    </span>
                                </Tooltip>
                            ) : null}
                            {flow.status !== 'archived' ? (
                                <Button variant="ghost" size="icon" className="igr:size-8" aria-label={t('flows.builder.archive')} onClick={() => setDialog('archive')}>
                                    <Archive />
                                </Button>
                            ) : null}
                        </>
                    ) : null}
                </div>
            </header>
            {showResults ? <ResultsBar data={analytics.data} days={days} onDays={setDays} loading={analytics.isFetching} /> : null}
            {readOnly && !canManage ? <p className="igr:border-b igr:bg-muted/50 igr:px-3 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{t('flows.builder.readOnly')}</p> : null}

            <div className="igr:flex igr:min-h-0 igr:flex-1">
                {/* Paleta */}
                {!readOnly ? (
                    <nav aria-label={t('flows.builder.palette')} className="igr:hidden igr:w-48 igr:shrink-0 igr:overflow-y-auto igr:border-r igr:p-2 igr:md:block">
                        <p className="igr:px-1 igr:pb-2 igr:text-[11px] igr:text-muted-foreground">{t('flows.builder.paletteHelp')}</p>
                        {PALETTE.map((group) => (
                            <div key={group.category} className="igr:mb-3">
                                <h2 className="igr:px-1 igr:pb-1 igr:text-[11px] igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t(`flows.categories.${group.category}`)}</h2>
                                {group.types.map((type) => {
                                    const meta = metaOf(type);
                                    const Icon = meta.icon;
                                    const button = (
                                        <button
                                            type="button"
                                            disabled={meta.phase !== undefined}
                                            onClick={() => addNode(type)}
                                            className="igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:text-[12.5px] igr:outline-none igr:hover:bg-accent igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:disabled:cursor-not-allowed igr:disabled:opacity-50"
                                        >
                                            <Icon className="igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                            <span className="igr:flex-1 igr:truncate">{t(`flows.nodes.${type}`)}</span>
                                            {meta.phase ? <span className="igr:text-[10px] igr:text-muted-foreground">F{meta.phase}</span> : null}
                                        </button>
                                    );
                                    return meta.phase ? (
                                        <Tooltip key={type} side="right" content={t('flows.config.laterPhase', { phase: meta.phase })}>
                                            <span className="igr:block">{button}</span>
                                        </Tooltip>
                                    ) : (
                                        <div key={type}>{button}</div>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>
                ) : null}

                {/* Lienzo */}
                <div ref={canvasRef} className="igr:relative igr:min-w-0 igr:flex-1" aria-label={t('flows.builder.canvasLabel')}>
                    {issues[''] ? (
                        <div className="igr:absolute igr:left-3 igr:top-3 igr:z-10 igr:max-w-md igr:rounded-md igr:border igr:border-destructive/40 igr:bg-card igr:px-3 igr:py-2 igr:text-xs igr:text-destructive igr:shadow-sm">
                            {issues[''].join(' ')}
                        </div>
                    ) : null}
                    <CanvasContext.Provider value={ctx}>
                        <ReactFlow
                            nodes={nodes}
                            edges={edges}
                            nodeTypes={NODE_TYPES}
                            onNodesChange={(changes) => {
                                onNodesChange(changes);
                            }}
                            onEdgesChange={(changes) => {
                                onEdgesChange(changes);
                            }}
                            onNodeDragStop={markDirty}
                            onNodesDelete={(deleted) => {
                                if (deleted.length > 0) {
                                    setSelected('trigger');
                                    markDirty();
                                }
                            }}
                            onEdgesDelete={markDirty}
                            onConnect={onConnect}
                            onNodeClick={(_, node) => setSelected(node.id)}
                            onPaneClick={() => setSelected('trigger')}
                            nodesDraggable={!readOnly}
                            nodesConnectable={!readOnly}
                            deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
                            defaultEdgeOptions={{ type: 'smoothstep', markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 } }}
                            forceColorMode={dark ? 'dark' : 'light'}
                            onInit={onInit}
                            minZoom={0.3}
                            proOptions={{ hideAttribution: true }}
                        >
                            <Background gap={20} size={1} />
                            <Controls showInteractive={false} />
                            <MiniMap pannable zoomable className="igr:hidden igr:2xl:block" />
                        </ReactFlow>
                    </CanvasContext.Provider>
                </div>

                {/* Panel derecho */}
                <aside className="igr:w-80 igr:shrink-0 igr:overflow-y-auto igr:border-l igr:p-4 igr:xl:w-96">
                    {selectedNode === null || selectedNode.type === 'trigger' ? (
                        <SettingsPanel
                            flowId={flow.id}
                            value={settings}
                            options={options}
                            readOnly={readOnly}
                            errors={fieldErrors}
                            webhookUrl={flow.webhook?.url ?? null}
                            onChange={(next) => {
                                setSettings(next);
                                markDirty();
                            }}
                        />
                    ) : (
                        <NodePanel
                            key={selectedNode.id}
                            node={selectedNode}
                            options={options}
                            issues={issues[selectedNode.id] ?? []}
                            readOnly={readOnly}
                            webhookSecret={flow.webhook_secret}
                            onChange={(config) => updateConfig(selectedNode.id, config)}
                            onDelete={() => deleteNode(selectedNode.id)}
                        />
                    )}
                    {selectedNode === null ? <p className="igr:mt-4 igr:text-xs igr:text-muted-foreground">{t('flows.builder.selectHint')}</p> : null}
                </aside>
            </div>

            <TestDialog flowId={flow.id} open={dialog === 'test'} onOpenChange={(o) => setDialog(o ? 'test' : null)} nodes={graphNodes} />
            <RunsDialog flowId={flow.id} open={dialog === 'runs'} onOpenChange={(o) => setDialog(o ? 'runs' : null)} nodes={graphNodes} />
            <Dialog open={dialog === 'publish'} onOpenChange={(o) => setDialog(o ? 'publish' : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('flows.builder.publish')}</DialogTitle>
                        <DialogDescription>{t('flows.builder.publishHelp')}</DialogDescription>
                    </DialogHeader>
                    {flow.current_version !== null ? (
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input type="checkbox" className="igr:mt-0.5" checked={migrate} onChange={(e) => setMigrate(e.target.checked)} />
                            {t('flows.builder.migrate')}
                        </label>
                    ) : null}
                    <DialogFooter>
                        <Button loading={publish.isPending || update.isPending} onClick={doPublish}>
                            <Send />
                            {t('flows.builder.publish')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog open={dialog === 'archive'} onOpenChange={(o) => setDialog(o ? 'archive' : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('flows.builder.archive')}</DialogTitle>
                        <DialogDescription>{t('flows.builder.archiveConfirm')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="destructive" loading={status.isPending} onClick={() => changeStatus('archive')}>
                            <Archive />
                            {t('flows.builder.archive')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
