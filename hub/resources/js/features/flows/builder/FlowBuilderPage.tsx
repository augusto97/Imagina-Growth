import '@xyflow/react/dist/style.css';
import {
    Background,
    type Connection,
    type Edge,
    type EdgeChangeset,
    MarkerType,
    MiniMap,
    type NodeChangeset,
    type OnSelectionChangeParams,
    ReactFlow,
    type ReactFlowInstance,
    ReactFlowProvider,
    useEdgesState,
    useNodesState,
    useReactFlow,
} from '@xyflow/react';
import { Archive, ArrowLeft, BarChart3, Ellipsis, FlaskConical, Keyboard, Maximize2, Minimize2, Pause, Play, Route, Save, Send, Settings2, Wand2, X } from 'lucide-react';
import { type CSSProperties, type DragEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
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
import { metaOf } from '../catalog';
import { StatusBadge } from '../FlowsPage';
import { branchOf, edgeId, type FlowCanvasNode, freeBranch, fromCanvas, handleOf, nextNodeId, pruneEdges, toCanvas } from '../graph';
import type { FlowDetail, FlowOptions, GraphNode } from '../types';
import { CanvasToolbar, MOD } from './CanvasToolbar';
import { CanvasContext, type CanvasContextValue } from './context';
import { addAt, appendAfter, type CanvasGraph, connectedHandles, insertOnEdge } from './edit';
import { FlowEdge } from './FlowEdge';
import { FlowNode } from './FlowNode';
import { emptyHistory, type History, record, redo, undo } from './history';
import { IssuesMenu } from './IssuesMenu';
import { hasOverlap, layoutGraph, NODE_HEIGHT, NODE_WIDTH, readableViewport } from './layout';
import { MobileFlowView } from './MobileFlowView';
import { NodePanel } from './NodePanel';
import { Palette, ShortcutLabel, STEP_MIME } from './Palette';
import { PHONE_QUERY, useBuilderPrefs, useMedia, WIDE_QUERY } from './prefs';
import { ResultsBar } from './ResultsBar';
import { RunsDialog } from './RunsDialog';
import { type FlowSettings, SettingsPanel } from './SettingsPanel';
import { ShortcutsDialog } from './ShortcutsDialog';
import { TestDialog } from './TestDialog';

const NODE_TYPES = { flow: FlowNode };
const EDGE_TYPES = { flow: FlowEdge };
const DEFAULT_EDGE = { type: 'flow', markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 } };
const EDGE_COLORS = { '--xy-edge-stroke': 'var(--muted-foreground)', '--xy-edge-stroke-selected': 'var(--primary)' } as CSSProperties;
/** Por debajo de este zoom el texto de los pasos no se lee: se muestra el comienzo del flujo y se desplaza. */
const READABLE_ZOOM = 0.9;

export function FlowBuilderPage() {
    const { flowId = '' } = useParams();
    const { workspace } = useWorkspace();
    const flow = useFlow(workspace.id, flowId);
    const options = useFlowOptions(workspace.id);
    const phone = useMedia(PHONE_QUERY);

    if (flow.isPending || options.isPending) {
        return <Skeleton className="igr:m-4 igr:h-[70vh]" />;
    }
    if (flow.isError || options.isError) {
        return <ErrorState error={flow.error ?? options.error} onRetry={() => void (flow.isError ? flow.refetch() : options.refetch())} />;
    }
    // En el celular el lienzo no se puede usar: lista de pasos en solo lectura.
    if (phone) {
        return <MobileFlowView flow={flow.data} options={options.data} />;
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

/** Lo que deshacer y rehacer devuelven: el lienzo y los ajustes del flujo. */
interface Snapshot {
    nodes: FlowCanvasNode[];
    edges: Edge[];
    settings: FlowSettings;
}

interface Actions {
    addAfter: (nodeId: string, branch: string | null, type: string) => void;
    insert: (edgeId: string, type: string) => void;
}

const sizeOf = (n: FlowCanvasNode) => ({ width: n.measured?.width ?? NODE_WIDTH, height: n.measured?.height ?? NODE_HEIGHT });

/** El foco está en un campo, un diálogo o un menú: las teclas sueltas no son atajos del lienzo. */
function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }
    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.closest('[role="dialog"],[role="menu"],[role="listbox"]') !== null;
}

function Builder({ flow, options }: { flow: FlowDetail; options: FlowOptions }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const rf = useReactFlow<FlowCanvasNode, Edge>();
    const canManage = useCan('manageMarketing');
    const readOnly = !canManage || flow.status === 'archived';
    const theme = useUi((s) => s.theme);
    const systemDark = useMedia('(prefers-color-scheme: dark)');
    const dark = theme === 'dark' || (theme === 'system' && systemDark);
    const wide = useMedia(WIDE_QUERY);
    const prefs = useBuilderPrefs();
    const paletteOpen = prefs.paletteOpen ?? wide;
    const minimap = prefs.minimap ?? wide;

    const initial = useMemo(() => toCanvas(flow.graph), [flow.graph]);
    const [nodes, setNodes, onNodesChange] = useNodesState<FlowCanvasNode>(initial.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>(initial.edges);
    const [settings, setSettings] = useState<FlowSettings>(() => settingsOf(flow));
    // Panel lateral: 'trigger' = ajustes del flujo, el ID de un paso o cerrado. Un flujo nuevo abre los ajustes.
    const [panel, setPanel] = useState<string | null>(() => (flow.trigger.type === null ? 'trigger' : null));
    const [dirty, setDirty] = useState(false);
    const [issues, setIssues] = useState<Record<string, string[]>>(flow.issues);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [showResults, setShowResults] = useState(false);
    const [days, setDays] = useState(30);
    const [dialog, setDialog] = useState<'test' | 'runs' | 'publish' | 'archive' | 'shortcuts' | null>(null);
    const [migrate, setMigrate] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const [overlapDismissed, setOverlapDismissed] = useState(false);
    const [history, setHistory] = useState<History<Snapshot>>(() => emptyHistory());
    const dragging = useRef(false);
    const canvasRef = useRef<HTMLDivElement>(null);

    // Vista inicial: todo el flujo si cabe legible; si no, el comienzo arriba y centrado.
    const onInit = useCallback(
        (instance: ReactFlowInstance<FlowCanvasNode, Edge>) => {
            const el = canvasRef.current;
            const viewport = el
                ? readableViewport(
                      initial.nodes.map((n) => ({ ...n.position, ...sizeOf(n) })),
                      { width: el.clientWidth, height: el.clientHeight },
                      READABLE_ZOOM,
                  )
                : null;
            if (viewport) {
                void instance.setViewport(viewport);
            }
        },
        [initial.nodes],
    );
    const showFlow = useCallback(
        (list: FlowCanvasNode[]) => {
            const el = canvasRef.current;
            const viewport = el
                ? readableViewport(
                      list.map((n) => ({ ...n.position, ...sizeOf(n) })),
                      { width: el.clientWidth, height: el.clientHeight },
                      READABLE_ZOOM,
                  )
                : null;
            if (viewport) {
                void rf.setViewport(viewport, { duration: 250 });
            }
        },
        [rf],
    );

    const update = useUpdateFlow(workspace.id, flow.id);
    const publish = usePublishFlow(workspace.id, flow.id);
    const status = useFlowStatus(workspace.id, flow.id);
    const restoreVersion = useRestoreVersion(workspace.id, flow.id);
    const analytics = useFlowAnalytics(workspace.id, flow.id, days, showResults);

    const markDirty = useCallback(() => setDirty(true), []);
    const graphNodes = useMemo(() => nodes.map((n) => n.data.node), [nodes]);

    // Deshacer / rehacer: se guarda el estado de antes de cada cambio.
    const checkpoint = (key?: string) => {
        const before: Snapshot = { nodes, edges, settings };
        setHistory((h) => record(h, before, { key: key ?? null }));
    };
    const restore = (state: Snapshot) => {
        setNodes(state.nodes.map((n) => ({ ...n, selected: n.id === panel })));
        setEdges(state.edges.map((e) => ({ ...e, selected: false })));
        setSettings(state.settings);
        if (panel !== null && !state.nodes.some((n) => n.id === panel)) {
            setPanel(null);
        }
        setDirty(true);
    };
    const doUndo = () => {
        const result = undo(history, { nodes, edges, settings });
        if (result) {
            setHistory(result.history);
            restore(result.state);
        }
    };
    const doRedo = () => {
        const result = redo(history, { nodes, edges, settings });
        if (result) {
            setHistory(result.history);
            restore(result.state);
        }
    };

    const applyFlow = useCallback(
        (next: FlowDetail) => {
            const canvas = toCanvas(next.graph);
            setNodes(canvas.nodes);
            setEdges(canvas.edges);
            setSettings(settingsOf(next));
            setIssues(next.issues);
            setHistory(emptyHistory());
            setDirty(false);
        },
        [setNodes, setEdges],
    );

    const handleError = useCallback((error: unknown, title: string) => {
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
                setPanel('trigger');
            }
            toast.error(title, error.message);
        } else {
            toast.error(title, isApiError(error) ? error.message : undefined);
        }
    }, []);

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
                        toast.success(
                            t('flows.builder.published', { version: next.current_version?.version ?? '' }),
                            next.migrated ? t('flows.builder.migrated', { count: next.migrated }) : undefined,
                        );
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

    // Avisa al cerrar la pestaña con cambios sin guardar.
    useEffect(() => {
        if (!dirty) {
            return;
        }
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [dirty]);

    // --- Cambios del lienzo ---

    const handleNodesChange = (changes: NodeChangeset<FlowCanvasNode>) => {
        if (!readOnly) {
            if (changes.getByType('remove').length > 0) {
                checkpoint('delete');
                markDirty();
            } else if (!dragging.current && changes.getByType('position').some((c) => c.position !== undefined)) {
                // Mover con las flechas: un solo paso de deshacer por tanda.
                checkpoint('keymove');
                markDirty();
            }
        }
        onNodesChange(changes);
    };
    const handleEdgesChange = (changes: EdgeChangeset<Edge>) => {
        if (!readOnly && changes.getByType('remove').length > 0) {
            checkpoint('delete');
            markDirty();
        }
        onEdgesChange(changes);
    };

    const onConnect = (c: Connection) => {
        if (readOnly || c.source === c.target) {
            return;
        }
        checkpoint();
        const handle = handleOf(c.sourceHandle);
        const id = edgeId(c.source, branchOf(handle));
        // Cada salida va a un solo paso: la nueva conexión reemplaza a la anterior.
        setEdges((eds) => [
            ...eds.filter((e) => !(e.source === c.source && (e.sourceHandle ?? 'next') === handle)),
            { id, source: c.source, target: c.target, sourceHandle: handle, targetHandle: 'in' },
        ]);
        markDirty();
    };

    const newNode = (type: string): GraphNode => ({ id: nextNodeId(nodes), type, config: metaOf(type).defaults() });
    const applyEdit = (next: CanvasGraph, select: string) => {
        checkpoint();
        setNodes(next.nodes);
        setEdges(next.edges);
        setPanel(select);
        markDirty();
    };
    // Desde la lista: debajo del paso abierto (o el elegido, o el último) y conectado a su primera salida libre.
    const addFromPalette = (type: string) => {
        if (readOnly) {
            return;
        }
        const from = nodes.find((n) => n.id === panel) ?? nodes.find((n) => n.selected) ?? nodes.at(-1);
        const node = newNode(type);
        applyEdit(appendAfter({ nodes, edges }, from?.id ?? null, from ? freeBranch(from.data.node, edges) : undefined, node), node.id);
    };
    const addAfter = (nodeId: string, branch: string | null, type: string) => {
        const node = newNode(type);
        applyEdit(appendAfter({ nodes, edges }, nodeId, branch, node), node.id);
    };
    const insert = (id: string, type: string) => {
        const node = newNode(type);
        applyEdit(insertOnEdge({ nodes, edges }, id, node), node.id);
    };
    const onDragOver = (e: DragEvent) => {
        if (!readOnly && e.dataTransfer.types.includes(STEP_MIME)) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        }
    };
    const onDrop = (e: DragEvent) => {
        const type = e.dataTransfer.getData(STEP_MIME);
        if (readOnly || type === '') {
            return;
        }
        e.preventDefault();
        const node = newNode(type);
        applyEdit(addAt({ nodes, edges }, node, rf.screenToFlowPosition({ x: e.clientX, y: e.clientY })), node.id);
    };

    const updateConfig = (id: string, config: Record<string, unknown>) => {
        checkpoint(`config:${id}`);
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
        checkpoint();
        setNodes((ns) => ns.filter((n) => n.id !== id));
        setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
        setPanel(null);
        markDirty();
    };

    const arrange = () => {
        if (readOnly) {
            return;
        }
        const positions = layoutGraph(fromCanvas(nodes, edges), Object.fromEntries(nodes.map((n) => [n.id, sizeOf(n)])));
        checkpoint();
        const next = nodes.map((n) => {
            const position = positions[n.id];
            return position ? { ...n, position } : n;
        });
        setNodes(next);
        markDirty();
        requestAnimationFrame(() => showFlow(next));
    };

    /** Lleva a un paso: lo elige, abre su panel y lo centra. */
    const focusNode = (id: string) => {
        setNodes((ns) => ns.map((n) => ({ ...n, selected: n.id === id })));
        setPanel(id);
    };

    // El paso abierto queda a la vista cuando el panel se abre (el lienzo se angosta).
    useEffect(() => {
        if (panel === null) {
            return;
        }
        const frame = requestAnimationFrame(() => {
            const el = canvasRef.current;
            const node = rf.getNode(panel);
            if (!el || !node) {
                return;
            }
            const { x, y, zoom } = rf.getViewport();
            const { width, height } = sizeOf(node);
            const left = node.position.x * zoom + x;
            const top = node.position.y * zoom + y;
            const margin = 24;
            if (left < margin || top < margin || left + width * zoom > el.clientWidth - margin || top + height * zoom > el.clientHeight - margin) {
                // Se respeta el zoom de la persona salvo que el texto ya no se lea.
                const z = zoom < 0.45 ? READABLE_ZOOM : zoom;
                void rf.setViewport(
                    {
                        x: el.clientWidth / 2 - (node.position.x + width / 2) * z,
                        y: Math.min(el.clientHeight / 2, el.clientHeight / 3 + (height * z) / 2) - (node.position.y + height / 2) * z,
                        zoom: z,
                    },
                    { duration: 250 },
                );
            }
        });
        return () => cancelAnimationFrame(frame);
    }, [panel, rf]);

    const onSelectionChange = useCallback(({ nodes: picked }: OnSelectionChangeParams<FlowCanvasNode, Edge>) => {
        const only = picked.length === 1 ? picked[0] : undefined;
        if (only) {
            setPanel(only.id);
        }
    }, []);

    // --- Atajos de teclado ---
    const togglePalette = () => prefs.setPaletteOpen(!paletteOpen);
    const toggleMinimap = () => prefs.setMinimap(!minimap);
    const onKey = (e: KeyboardEvent) => {
        const mod = e.metaKey || e.ctrlKey;
        const key = e.key.toLowerCase();
        if (mod && key === 's') {
            e.preventDefault();
            save();
            return;
        }
        if (dialog !== null) {
            return;
        }
        // Esc cierra el panel aunque el foco esté en uno de sus campos (no en un menú abierto).
        if (e.key === 'Escape' && !(e.target instanceof HTMLElement && e.target.closest('[role="menu"],[role="listbox"]') !== null)) {
            if (panel !== null) {
                setPanel(null);
            } else if (fullscreen) {
                setFullscreen(false);
            }
            return;
        }
        if (isTyping(e.target)) {
            return;
        }
        if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) {
            e.preventDefault();
            doRedo();
            return;
        }
        if (mod && key === 'z') {
            e.preventDefault();
            doUndo();
            return;
        }
        if (mod || e.altKey) {
            return;
        }
        switch (e.key) {
            case '?':
                setDialog('shortcuts');
                return;
            case '+':
            case '=':
                void rf.zoomIn({ duration: 150 });
                return;
            case '-':
                void rf.zoomOut({ duration: 150 });
                return;
            case '0':
                void rf.fitView({ padding: 0.12, maxZoom: 1, duration: 200 });
                return;
        }
        switch (key) {
            case 'o':
                arrange();
                break;
            case 'p':
                if (!readOnly) {
                    togglePalette();
                }
                break;
            case 'm':
                toggleMinimap();
                break;
            case 'f':
                setFullscreen((v) => !v);
                break;
        }
    };
    const keyRef = useRef(onKey);
    useEffect(() => {
        keyRef.current = onKey;
    });
    useEffect(() => {
        const listener = (e: KeyboardEvent) => keyRef.current(e);
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, []);

    // Acciones estables para los «+» de los pasos y las conexiones (no rehacen el contexto en cada cambio).
    const actions = useRef<Actions | null>(null);
    useEffect(() => {
        actions.current = { addAfter, insert };
    });
    const onAddAfter = useCallback((nodeId: string, branch: string | null, type: string) => actions.current?.addAfter(nodeId, branch, type), []);
    const onInsert = useCallback((id: string, type: string) => actions.current?.insert(id, type), []);

    const connected = useMemo(() => connectedHandles(edges), [edges]);
    // Pasos encimados (acomodos viejos con pasos que crecieron): se ofrece ordenar.
    const overlapping = useMemo(() => hasOverlap(nodes.filter((n) => n.measured?.width !== undefined && !n.dragging).map((n) => ({ ...n.position, ...sizeOf(n) }))), [nodes]);
    const selectedNode = panel === null || panel === 'trigger' ? null : (graphNodes.find((n) => n.id === panel) ?? null);
    const ctx = useMemo<CanvasContextValue>(
        () => ({
            options,
            trigger: settings.trigger,
            goal: settings.goal,
            issues,
            stats: showResults ? (analytics.data?.nodes ?? null) : null,
            readOnly,
            locale: workspace.locale,
            connected,
            onAddAfter,
            onInsert,
        }),
        [options, settings.trigger, settings.goal, issues, showResults, analytics.data, readOnly, workspace.locale, connected, onAddAfter, onInsert],
    );
    const ariaLabelConfig = useMemo(
        () => ({
            'node.a11yDescription.default': t('flows.canvas.a11y.node'),
            'node.a11yDescription.keyboardDisabled': t('flows.canvas.a11y.node'),
            'node.a11yDescription.ariaLiveMessage': ({ direction, x, y }: { direction: string; x: number; y: number }) =>
                t('flows.canvas.a11y.moved', { direction: t(`flows.canvas.a11y.directions.${direction}`), x, y }),
            'edge.a11yDescription.default': t('flows.canvas.a11y.edge'),
            'controls.ariaLabel': t('flows.canvas.toolbar'),
            'controls.zoomIn.ariaLabel': t('flows.canvas.zoomIn'),
            'controls.zoomOut.ariaLabel': t('flows.canvas.zoomOut'),
            'controls.fitView.ariaLabel': t('flows.canvas.fit'),
            'controls.interactive.ariaLabel': t('flows.canvas.toolbar'),
            'minimap.ariaLabel': t('flows.canvas.minimap'),
            'handle.ariaLabel': t('flows.canvas.a11y.handle'),
        }),
        [t],
    );

    const versionText =
        flow.has_draft || dirty
            ? t('flows.builder.draft', { version: flow.draft_version ?? (flow.current_version?.version ?? 0) + 1 })
            : flow.current_version
              ? t('flows.builder.version', { version: flow.current_version.version })
              : null;

    return (
        <div className={cn('igr:flex igr:flex-col igr:bg-background', fullscreen ? 'igr:fixed igr:inset-0 igr:z-40' : 'igr:min-h-0 igr:flex-1')}>
            {/* Barra superior: una sola línea con nombre, estado, validación y las acciones. */}
            <header className="igr:@container igr:flex igr:h-12 igr:shrink-0 igr:items-center igr:gap-2 igr:border-b igr:px-2">
                <Button asChild variant="ghost" size="icon" className="igr:shrink-0">
                    <Link to={`/w/${workspace.id}/flujos`} aria-label={t('flows.builder.back')}>
                        <ArrowLeft />
                    </Link>
                </Button>
                <div className="igr:flex igr:min-w-0 igr:flex-1 igr:items-center igr:gap-2">
                    <h1 className="igr:min-w-0 igr:text-[15px] igr:font-semibold">
                        <Tooltip
                            content={
                                <>
                                    <span className="igr:block igr:font-medium">{settings.name}</span>
                                    {t('flows.canvas.openSettings')}
                                </>
                            }
                        >
                            <button
                                type="button"
                                onClick={() => setPanel('trigger')}
                                className="igr:block igr:max-w-full igr:truncate igr:rounded-md igr:px-1.5 igr:py-0.5 igr:text-left igr:hover:bg-accent"
                            >
                                {settings.name}
                            </button>
                        </Tooltip>
                    </h1>
                    <span className="igr:shrink-0">
                        <StatusBadge status={flow.status} />
                    </span>
                    {versionText ? <span className="igr:hidden igr:shrink-0 igr:text-xs igr:text-muted-foreground igr:@6xl:inline">{versionText}</span> : null}
                    <IssuesMenu issues={issues} nodes={graphNodes} onFocus={focusNode} />
                    {dirty ? (
                        <span className="igr:hidden igr:shrink-0 igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium igr:text-warning igr:@6xl:inline-flex">
                            <span className="igr:size-1.5 igr:rounded-full igr:bg-warning" aria-hidden />
                            {t('flows.builder.unsaved')}
                        </span>
                    ) : null}
                </div>
                <div className="igr:flex igr:shrink-0 igr:items-center igr:gap-1.5">
                    <Button
                        variant={showResults ? 'secondary' : 'ghost'}
                        size="sm"
                        aria-pressed={showResults}
                        aria-label={t('flows.builder.results')}
                        onClick={() => setShowResults((v) => !v)}
                    >
                        <BarChart3 />
                        <span className="igr:hidden igr:@4xl:inline">{t('flows.builder.results')}</span>
                    </Button>
                    {canManage ? (
                        <>
                            <Button variant="outline" size="sm" onClick={() => setDialog('test')}>
                                <FlaskConical />
                                {t('flows.builder.test')}
                            </Button>
                            <Tooltip content={<ShortcutLabel text={dirty ? t('flows.builder.unsaved') : t('flows.builder.save')} keys={`${MOD} S`} />}>
                                <span className="igr:inline-flex">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={readOnly || !dirty}
                                        loading={update.isPending && dialog === null}
                                        onClick={() => save()}
                                        className="igr:relative"
                                    >
                                        <Save />
                                        {t('flows.builder.save')}
                                        {dirty ? (
                                            <span className="igr:absolute igr:-top-1 igr:-right-1 igr:size-2.5 igr:rounded-full igr:border-2 igr:border-background igr:bg-warning igr:@6xl:hidden">
                                                <span className="igr:sr-only">{t('flows.builder.unsaved')}</span>
                                            </span>
                                        ) : null}
                                    </Button>
                                </span>
                            </Tooltip>
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
                                    <span className="igr:inline-flex">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={flow.current_version === null || dirty}
                                            loading={status.isPending}
                                            onClick={() => changeStatus('activate')}
                                        >
                                            <Play />
                                            {t('flows.builder.activate')}
                                        </Button>
                                    </span>
                                </Tooltip>
                            ) : null}
                        </>
                    ) : null}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={t('flows.canvas.more')}>
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="igr:max-h-[70vh] igr:w-72 igr:overflow-y-auto">
                            <DropdownMenuItem onSelect={() => setPanel('trigger')}>
                                <Settings2 />
                                {t('flows.builder.settings')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDialog('runs')}>
                                <Route />
                                {t('flows.builder.runs')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDialog('shortcuts')}>
                                <Keyboard />
                                {t('flows.canvas.shortcuts')}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>{t('flows.builder.versions')}</DropdownMenuLabel>
                            {flow.versions.length === 0 ? <div className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{t('flows.canvas.noVersions')}</div> : null}
                            {flow.versions.map((v) => (
                                <DropdownMenuItem
                                    key={v.id}
                                    disabled={readOnly}
                                    onSelect={() =>
                                        restoreVersion.mutate(v.id, {
                                            onSuccess: (next) => {
                                                applyFlow(next);
                                                toast.success(t('flows.builder.restored', { version: v.version }));
                                            },
                                        })
                                    }
                                >
                                    <span className="igr:flex-1">
                                        {t('flows.builder.version', { version: v.version })}
                                        <span className="igr:block igr:text-[11px] igr:text-muted-foreground">
                                            {formatDateTime(v.published_at, workspace.locale, workspace.timezone)}
                                        </span>
                                    </span>
                                    <span className="igr:text-[11px] igr:text-muted-foreground">{t('flows.builder.restore')}</span>
                                </DropdownMenuItem>
                            ))}
                            {canManage && flow.status !== 'archived' ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={() => setDialog('archive')} className="igr:text-destructive igr:[&_svg]:text-destructive">
                                        <Archive />
                                        {t('flows.builder.archive')}
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Tooltip content={<ShortcutLabel text={fullscreen ? t('flows.canvas.exitFullscreen') : t('flows.canvas.fullscreen')} keys="F" />}>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={fullscreen ? t('flows.canvas.exitFullscreen') : t('flows.canvas.fullscreen')}
                            aria-pressed={fullscreen}
                            onClick={() => setFullscreen((v) => !v)}
                        >
                            {fullscreen ? <Minimize2 /> : <Maximize2 />}
                        </Button>
                    </Tooltip>
                </div>
            </header>
            {showResults ? <ResultsBar data={analytics.data} days={days} onDays={setDays} loading={analytics.isFetching} /> : null}
            {readOnly && !canManage ? (
                <p className="igr:border-b igr:bg-muted/50 igr:px-3 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{t('flows.builder.readOnly')}</p>
            ) : null}

            <div className="igr:flex igr:min-h-0 igr:flex-1">
                {!readOnly ? <Palette open={paletteOpen} onToggle={togglePalette} onAdd={addFromPalette} /> : null}

                {/* Lienzo */}
                <div
                    ref={canvasRef}
                    role="region"
                    aria-label={t('flows.builder.canvasLabel')}
                    className="igr:relative igr:min-w-0 igr:flex-1"
                    onDragOver={onDragOver}
                    onDrop={onDrop}
                >
                    {issues[''] ? (
                        <div className="igr:absolute igr:top-3 igr:left-3 igr:z-10 igr:max-w-md igr:rounded-md igr:border igr:border-destructive/40 igr:bg-card igr:px-3 igr:py-2 igr:text-xs igr:text-destructive igr:shadow-sm">
                            {issues[''].join(' ')}
                        </div>
                    ) : null}
                    {!readOnly && nodes.length <= 1 ? (
                        <p className="igr:absolute igr:top-3 igr:left-1/2 igr:z-10 igr:max-w-sm igr:-translate-x-1/2 igr:rounded-md igr:border igr:bg-card igr:px-3 igr:py-2 igr:text-center igr:text-[12.5px] igr:text-muted-foreground igr:shadow-sm">
                            {t('flows.canvas.emptyHint')}
                        </p>
                    ) : null}
                    {!readOnly && overlapping && !overlapDismissed ? (
                        <div
                            role="status"
                            className="igr:absolute igr:top-3 igr:right-3 igr:z-10 igr:flex igr:max-w-[calc(100%-1.5rem)] igr:items-center igr:gap-2 igr:rounded-md igr:border igr:bg-card igr:py-1.5 igr:pr-1 igr:pl-3 igr:text-[12.5px] igr:shadow-sm"
                        >
                            {t('flows.canvas.overlap')}
                            <Button size="sm" variant="outline" onClick={arrange}>
                                <Wand2 />
                                {t('flows.canvas.arrangeShort')}
                            </Button>
                            <Button size="icon" variant="ghost" className="igr:size-7" aria-label={t('flows.canvas.dismiss')} onClick={() => setOverlapDismissed(true)}>
                                <X />
                            </Button>
                        </div>
                    ) : null}
                    <CanvasContext.Provider value={ctx}>
                        <ReactFlow
                            nodes={nodes}
                            edges={edges}
                            nodeTypes={NODE_TYPES}
                            edgeTypes={EDGE_TYPES}
                            onNodesChange={handleNodesChange}
                            onEdgesChange={handleEdgesChange}
                            onNodeDragStart={() => {
                                if (!readOnly) {
                                    dragging.current = true;
                                    checkpoint();
                                }
                            }}
                            onNodeDragStop={() => {
                                dragging.current = false;
                                if (!readOnly) {
                                    markDirty();
                                }
                            }}
                            onNodesDelete={(deleted) => {
                                if (deleted.some((n) => n.id === panel)) {
                                    setPanel(null);
                                }
                            }}
                            onConnect={onConnect}
                            onSelectionChange={onSelectionChange}
                            onNodeClick={(_, node) => setPanel(node.id)}
                            onPaneClick={() => setPanel(null)}
                            selectNodesOnDrag={false}
                            nodesDraggable={!readOnly}
                            nodesConnectable={!readOnly}
                            deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
                            defaultEdgeOptions={DEFAULT_EDGE}
                            forceColorMode={dark ? 'dark' : 'light'}
                            onInit={onInit}
                            minZoom={0.2}
                            maxZoom={1.5}
                            panOnScroll
                            ariaLabelConfig={ariaLabelConfig}
                            proOptions={{ hideAttribution: true }}
                            // Colores de las conexiones con los del tema; la elegida o con foco, en el color principal.
                            style={EDGE_COLORS}
                        >
                            <Background gap={20} size={1} />
                            <CanvasToolbar
                                readOnly={readOnly}
                                canUndo={history.past.length > 0}
                                canRedo={history.future.length > 0}
                                minimap={minimap}
                                onArrange={arrange}
                                onUndo={doUndo}
                                onRedo={doRedo}
                                onToggleMinimap={toggleMinimap}
                                onShortcuts={() => setDialog('shortcuts')}
                            />
                            {minimap ? (
                                <MiniMap
                                    pannable
                                    zoomable
                                    position="bottom-right"
                                    style={{ width: 176, height: 120, margin: 12 }}
                                    nodeBorderRadius={8}
                                    nodeColor={(n) => (issues[n.id] ? 'var(--destructive)' : n.id === 'trigger' ? 'var(--primary)' : 'var(--muted-foreground)')}
                                    className="igr:overflow-hidden igr:rounded-lg igr:border igr:shadow-md"
                                />
                            ) : null}
                        </ReactFlow>
                    </CanvasContext.Provider>
                </div>

                {/* Panel lateral: al lado del lienzo (no encima) y se cierra para tener todo el ancho. */}
                {panel !== null && (panel === 'trigger' || selectedNode !== null) ? (
                    <aside
                        aria-label={panel === 'trigger' ? t('flows.builder.settings') : t('flows.canvas.stepPanel')}
                        className="igr:flex igr:w-80 igr:shrink-0 igr:flex-col igr:border-l igr:bg-background igr:2xl:w-96"
                    >
                        <div className="igr:flex igr:h-10 igr:shrink-0 igr:items-center igr:justify-between igr:gap-2 igr:border-b igr:pr-1.5 igr:pl-4">
                            <span className="igr:truncate igr:text-xs igr:font-medium igr:text-muted-foreground">
                                {panel === 'trigger' ? t('flows.canvas.flowPanel') : t('flows.canvas.stepPanel')}
                            </span>
                            <Tooltip content={<ShortcutLabel text={t('flows.canvas.closePanel')} keys="Esc" />}>
                                <Button variant="ghost" size="icon" className="igr:size-7" aria-label={t('flows.canvas.closePanel')} onClick={() => setPanel(null)}>
                                    <X />
                                </Button>
                            </Tooltip>
                        </div>
                        <div className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto igr:p-4">
                            {selectedNode === null ? (
                                <SettingsPanel
                                    flowId={flow.id}
                                    value={settings}
                                    options={options}
                                    readOnly={readOnly}
                                    errors={fieldErrors}
                                    webhookUrl={flow.webhook?.url ?? null}
                                    onChange={(next) => {
                                        checkpoint('settings');
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
                        </div>
                    </aside>
                ) : null}
            </div>

            <TestDialog flowId={flow.id} open={dialog === 'test'} onOpenChange={(o) => setDialog(o ? 'test' : null)} nodes={graphNodes} />
            <RunsDialog flowId={flow.id} open={dialog === 'runs'} onOpenChange={(o) => setDialog(o ? 'runs' : null)} nodes={graphNodes} />
            <ShortcutsDialog open={dialog === 'shortcuts'} onOpenChange={(o) => setDialog(o ? 'shortcuts' : null)} />
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
