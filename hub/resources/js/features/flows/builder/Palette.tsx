import { ChevronsLeft, ChevronsRight, Search } from 'lucide-react';
import { type DragEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import { metaOf, PALETTE } from '../catalog';

/** Tipo de dato al arrastrar un paso de la lista al lienzo. */
export const STEP_MIME = 'application/x-igr-flow-step';

function onDragStart(event: DragEvent, type: string) {
    event.dataTransfer.setData(STEP_MIME, type);
    event.dataTransfer.effectAllowed = 'copy';
}

/**
 * Lista de pasos para agregar, plegable. Abierta: por categoría, con buscador. Plegada: una
 * columna de íconos con su nombre al pasar el mouse. Clic = después del paso elegido; también se
 * pueden arrastrar al lienzo.
 */
export function Palette({ open, onToggle, onAdd }: { open: boolean; onToggle: () => void; onAdd: (type: string) => void }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const q = query.trim().toLocaleLowerCase();
    const groups = PALETTE.map((group) => ({
        ...group,
        types: group.types.filter((type) => q === '' || t(`flows.nodes.${type}`, { defaultValue: type }).toLocaleLowerCase().includes(q)),
    })).filter((g) => g.types.length > 0);

    if (!open) {
        return (
            <nav
                aria-label={t('flows.builder.palette')}
                className="igr:flex igr:w-12 igr:shrink-0 igr:flex-col igr:items-center igr:gap-0.5 igr:overflow-y-auto igr:border-r igr:bg-background igr:py-2"
            >
                <Tooltip side="right" content={<ShortcutLabel text={t('flows.canvas.showPalette')} keys="P" />}>
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-label={t('flows.canvas.showPalette')}
                        aria-expanded={false}
                        className="igr:mb-1 igr:grid igr:size-8 igr:place-items-center igr:rounded-md igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground"
                    >
                        <ChevronsRight className="igr:size-4" aria-hidden />
                    </button>
                </Tooltip>
                {PALETTE.map((group, gi) => (
                    <div key={group.category} className={cn('igr:flex igr:flex-col igr:items-center igr:gap-0.5', gi > 0 && 'igr:mt-1 igr:border-t igr:pt-1')}>
                        {group.types.map((type) => {
                            const meta = metaOf(type);
                            const Icon = meta.icon;
                            const name = t(`flows.nodes.${type}`, { defaultValue: type });
                            return (
                                <Tooltip key={type} side="right" content={meta.phase ? t('flows.config.laterPhase', { phase: meta.phase }) : name}>
                                    <button
                                        type="button"
                                        aria-label={name}
                                        disabled={meta.phase !== undefined}
                                        draggable={meta.phase === undefined}
                                        onDragStart={(e) => onDragStart(e, type)}
                                        onClick={() => onAdd(type)}
                                        className="igr:grid igr:size-8 igr:place-items-center igr:rounded-md igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground igr:disabled:cursor-not-allowed igr:disabled:opacity-50"
                                    >
                                        <Icon className="igr:size-4" aria-hidden />
                                    </button>
                                </Tooltip>
                            );
                        })}
                    </div>
                ))}
            </nav>
        );
    }

    return (
        <nav aria-label={t('flows.builder.palette')} className="igr:flex igr:w-52 igr:shrink-0 igr:flex-col igr:border-r igr:bg-background">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:pt-2.5">
                <h2 className="igr:text-[13px] igr:font-semibold">{t('flows.builder.palette')}</h2>
                <Tooltip side="right" content={<ShortcutLabel text={t('flows.canvas.hidePalette')} keys="P" />}>
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-label={t('flows.canvas.hidePalette')}
                        aria-expanded
                        className="igr:grid igr:size-7 igr:place-items-center igr:rounded-md igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground"
                    >
                        <ChevronsLeft className="igr:size-4" aria-hidden />
                    </button>
                </Tooltip>
            </div>
            <div className="igr:relative igr:px-2 igr:pt-2">
                <Search className="igr:pointer-events-none igr:absolute igr:top-1/2 igr:left-4 igr:mt-1 igr:size-3.5 igr:-translate-y-1/2 igr:text-muted-foreground" aria-hidden />
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('flows.canvas.searchSteps')}
                    aria-label={t('flows.canvas.searchSteps')}
                    className="igr:h-8 igr:pl-7 igr:text-[13px]"
                />
            </div>
            <p className="igr:px-3 igr:pt-2 igr:pb-1 igr:text-[11.5px] igr:leading-snug igr:text-muted-foreground">{t('flows.canvas.paletteHelp')}</p>
            <div className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto igr:px-2 igr:pb-3">
                {groups.length === 0 ? <p className="igr:px-1 igr:py-2 igr:text-[12px] igr:text-muted-foreground">{t('flows.canvas.noSteps')}</p> : null}
                {groups.map((group) => (
                    <div key={group.category} className="igr:mt-2">
                        <h3 className="igr:px-1 igr:pb-1 igr:text-[11px] igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">
                            {t(`flows.categories.${group.category}`)}
                        </h3>
                        {group.types.map((type) => {
                            const meta = metaOf(type);
                            const Icon = meta.icon;
                            const button = (
                                <button
                                    type="button"
                                    disabled={meta.phase !== undefined}
                                    draggable={meta.phase === undefined}
                                    onDragStart={(e) => onDragStart(e, type)}
                                    onClick={() => onAdd(type)}
                                    className="igr:flex igr:w-full igr:cursor-grab igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:text-[13px] igr:hover:bg-accent igr:active:cursor-grabbing igr:disabled:cursor-not-allowed igr:disabled:opacity-50"
                                >
                                    <Icon className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                    <span className="igr:flex-1 igr:truncate">{t(`flows.nodes.${type}`, { defaultValue: type })}</span>
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
            </div>
        </nav>
    );
}

/** Texto de ayuda con su atajo de teclado. */
export function ShortcutLabel({ text, keys }: { text: string; keys: string }) {
    return (
        <span className="igr:inline-flex igr:items-center igr:gap-1.5">
            {text}
            <Kbd className="igr:border-background/30 igr:bg-background/15 igr:text-background">{keys}</Kbd>
        </span>
    );
}
