import { Check, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { IconTile, type IconTone } from './icon-tile';

/**
 * Tarjeta para elegir una opción entre pocas (rediseño v2 §4: tipo de negocio y vertical en el asistente). Por dentro es
 * un `<input type="radio">` nativo (visualmente oculto) dentro de su `<label>`: las flechas del teclado cambian de
 * opción dentro del mismo `name`, el lector de pantalla anuncia «opción, 1 de 3» y el foco se ve en toda la tarjeta.
 * Agrúpalas en un `<fieldset>` con su `<legend>` (o `role="radiogroup"` con `aria-label`).
 *
 * - `icon` y `tone`: mosaico opcional; `badge`: una marca corta («Sugerida»).
 * - `size="sm"`: más compacta, para listas largas (las verticales).
 */
export function ChoiceCard({
    name,
    value,
    checked,
    onChange,
    title,
    description,
    icon,
    tone = 'indigo',
    badge,
    size = 'md',
    disabled,
    className,
}: {
    name: string;
    value: string;
    checked: boolean;
    onChange: (value: string) => void;
    title: ReactNode;
    description?: ReactNode;
    icon?: LucideIcon;
    tone?: IconTone;
    badge?: ReactNode;
    size?: 'sm' | 'md';
    disabled?: boolean;
    className?: string;
}) {
    return (
        <label
            className={cn(
                'igr:relative igr:flex igr:min-w-0 igr:cursor-pointer igr:items-start igr:gap-3 igr:rounded-xl igr:border igr:bg-card igr:text-left igr:shadow-xs igr:transition-[border-color,box-shadow,background-color] igr:duration-150',
                'igr:hover:border-primary/40 igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring igr:has-[:focus-visible]:ring-offset-2 igr:has-[:focus-visible]:ring-offset-background',
                size === 'sm' ? 'igr:p-3' : 'igr:p-4',
                checked && 'igr:border-primary igr:bg-brand-soft/40 igr:shadow-sm',
                disabled && 'igr:cursor-not-allowed igr:opacity-60',
                className,
            )}
        >
            <input type="radio" name={name} value={value} checked={checked} disabled={disabled} onChange={() => onChange(value)} className="igr:sr-only" />
            {icon ? <IconTile icon={icon} tone={tone} size={size === 'sm' ? 'sm' : 'md'} /> : null}
            <span className="igr:min-w-0 igr:flex-1">
                <span className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1">
                    <span className={cn('igr:font-semibold igr:text-foreground', size === 'sm' ? 'igr:text-[13px]' : 'igr:text-sm')}>{title}</span>
                    {badge}
                </span>
                {description ? <span className="igr:mt-0.5 igr:block igr:text-support igr:text-muted-foreground">{description}</span> : null}
            </span>
            <span
                aria-hidden
                className={cn(
                    'igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border igr:transition-colors',
                    checked ? 'igr:border-primary igr:bg-primary igr:text-primary-foreground' : 'igr:border-input igr:bg-card',
                )}
            >
                {checked ? <Check className="igr:size-3.5" strokeWidth={3} /> : null}
            </span>
        </label>
    );
}
