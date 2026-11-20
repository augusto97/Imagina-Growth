import { Panel, useReactFlow, useViewport } from '@xyflow/react';
import { Keyboard, MapIcon, Maximize, Minus, Plus, Redo2, Undo2, Wand2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import { ShortcutLabel } from './Palette';

export const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
export const MOD = IS_MAC ? '⌘' : 'Ctrl';

function ToolButton({
    label,
    keys,
    onClick,
    disabled,
    pressed,
    children,
    className,
}: {
    label: string;
    keys?: string;
    onClick: () => void;
    disabled?: boolean;
    pressed?: boolean;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Tooltip content={keys ? <ShortcutLabel text={label} keys={keys} /> : label}>
            <button
                type="button"
                aria-label={label}
                aria-pressed={pressed}
                disabled={disabled}
                onClick={onClick}
                className={cn(
                    'igr:inline-flex igr:h-8 igr:min-w-8 igr:items-center igr:justify-center igr:gap-1.5 igr:rounded-md igr:px-1.5 igr:text-[12.5px] igr:font-medium igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground igr:disabled:pointer-events-none igr:disabled:opacity-40 igr:[&_svg]:size-4',
                    pressed && 'igr:bg-accent igr:text-foreground',
                    className,
                )}
            >
                {children}
            </button>
        </Tooltip>
    );
}

const Divider = () => <span className="igr:mx-0.5 igr:h-5 igr:w-px igr:bg-border" aria-hidden />;

/** Barra del lienzo: zoom, ajustar a la vista, ordenar, deshacer y rehacer, minimapa y atajos. */
export function CanvasToolbar({
    readOnly,
    canUndo,
    canRedo,
    minimap,
    onArrange,
    onUndo,
    onRedo,
    onToggleMinimap,
    onShortcuts,
}: {
    readOnly: boolean;
    canUndo: boolean;
    canRedo: boolean;
    minimap: boolean;
    onArrange: () => void;
    onUndo: () => void;
    onRedo: () => void;
    onToggleMinimap: () => void;
    onShortcuts: () => void;
}) {
    const { t } = useTranslation();
    const rf = useReactFlow();
    const { zoom } = useViewport();

    return (
        <Panel position="bottom-left" style={{ margin: 12 }}>
            <div
                role="toolbar"
                aria-label={t('flows.canvas.toolbar')}
                className="igr:flex igr:items-center igr:gap-0.5 igr:rounded-lg igr:border igr:bg-card igr:p-1 igr:shadow-md"
            >
                <ToolButton label={t('flows.canvas.zoomOut')} keys="−" onClick={() => void rf.zoomOut({ duration: 150 })}>
                    <Minus aria-hidden />
                </ToolButton>
                <ToolButton label={t('flows.canvas.zoomReset')} onClick={() => void rf.zoomTo(1, { duration: 150 })} className="igr:w-12 igr:tabular-nums">
                    {Math.round(zoom * 100)}%
                </ToolButton>
                <ToolButton label={t('flows.canvas.zoomIn')} keys="+" onClick={() => void rf.zoomIn({ duration: 150 })}>
                    <Plus aria-hidden />
                </ToolButton>
                <ToolButton label={t('flows.canvas.fit')} keys="0" onClick={() => void rf.fitView({ padding: 0.12, maxZoom: 1, duration: 200 })}>
                    <Maximize aria-hidden />
                </ToolButton>
                {!readOnly ? (
                    <>
                        <Divider />
                        <ToolButton label={t('flows.canvas.arrange')} keys="O" onClick={onArrange}>
                            <Wand2 aria-hidden />
                            <span className="igr:hidden igr:pr-0.5 igr:xl:inline">{t('flows.canvas.arrangeShort')}</span>
                        </ToolButton>
                        <Divider />
                        <ToolButton label={t('flows.canvas.undo')} keys={`${MOD} Z`} onClick={onUndo} disabled={!canUndo}>
                            <Undo2 aria-hidden />
                        </ToolButton>
                        <ToolButton label={t('flows.canvas.redo')} keys={IS_MAC ? '⌘ ⇧ Z' : 'Ctrl Y'} onClick={onRedo} disabled={!canRedo}>
                            <Redo2 aria-hidden />
                        </ToolButton>
                    </>
                ) : null}
                <Divider />
                <ToolButton label={minimap ? t('flows.canvas.hideMinimap') : t('flows.canvas.showMinimap')} keys="M" onClick={onToggleMinimap} pressed={minimap}>
                    <MapIcon aria-hidden />
                </ToolButton>
                <ToolButton label={t('flows.canvas.shortcuts')} keys="?" onClick={onShortcuts}>
                    <Keyboard aria-hidden />
                </ToolButton>
            </div>
        </Panel>
    );
}
