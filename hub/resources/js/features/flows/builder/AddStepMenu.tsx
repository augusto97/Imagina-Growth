import { Plus } from 'lucide-react';
import { Fragment, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/cn';
import { metaOf, PALETTE } from '../catalog';

/** Tipos que se pueden agregar hoy (los de una fase posterior no). */
export function availableTypes(): { category: string; types: string[] }[] {
    return PALETTE.map((group) => ({ category: group.category, types: group.types.filter((type) => metaOf(type).phase === undefined) })).filter((g) => g.types.length > 0);
}

/**
 * Los eventos de React suben por el árbol de componentes aunque el menú se pinte en otro lugar:
 * sin esto, un clic o un Enter en el menú llegaría al paso o a la conexión de abajo y los elegiría.
 */
const stop = (event: SyntheticEvent) => event.stopPropagation();

/** Botón «+» que abre la lista de pasos para agregar uno ahí (en una conexión o una salida libre). */
export function AddStepMenu({ label, onPick, className }: { label: string; onPick: (type: string) => void; className?: string }) {
    const { t } = useTranslation();
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    title={label}
                    onClick={stop}
                    onKeyDown={stop}
                    onDoubleClick={stop}
                    className={cn(
                        'nodrag nopan igr:pointer-events-auto igr:grid igr:size-6 igr:place-items-center igr:rounded-full igr:border igr:border-input igr:bg-card igr:text-muted-foreground igr:shadow-sm igr:transition-colors igr:hover:border-primary igr:hover:bg-primary igr:hover:text-primary-foreground igr:data-[state=open]:border-primary igr:data-[state=open]:bg-primary igr:data-[state=open]:text-primary-foreground',
                        className,
                    )}
                >
                    <Plus className="igr:size-3.5" aria-hidden />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="center" className="igr:max-h-[min(70vh,30rem)] igr:w-60 igr:overflow-y-auto" onClick={stop} onKeyDown={stop} onPointerDown={stop}>
                <DropdownMenuLabel className="igr:font-medium igr:text-foreground">{t('flows.canvas.addStep')}</DropdownMenuLabel>
                {availableTypes().map((group, i) => (
                    <Fragment key={group.category}>
                        {i > 0 ? <DropdownMenuSeparator /> : null}
                        <DropdownMenuLabel>{t(`flows.categories.${group.category}`)}</DropdownMenuLabel>
                        {group.types.map((type) => {
                            const Icon = metaOf(type).icon;
                            return (
                                <DropdownMenuItem key={type} onSelect={() => onPick(type)}>
                                    <Icon aria-hidden />
                                    {t(`flows.nodes.${type}`, { defaultValue: type })}
                                </DropdownMenuItem>
                            );
                        })}
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
