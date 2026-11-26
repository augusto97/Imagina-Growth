import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * Tonos de los mosaicos por categoría (rediseño v2 §7): un ícono de Lucide dentro de un cuadro redondeado con el tinte
 * de su categoría. El ícono es gráfico (no texto): cada tono pasa 3:1 contra su fondo en claro y en oscuro.
 * `brand` es el degradado índigo-violeta de la marca, para lo destacado (una estrategia, la sección actual).
 */
export type IconTone = 'indigo' | 'violet' | 'sky' | 'emerald' | 'amber' | 'rose' | 'teal' | 'orange' | 'slate' | 'brand';

export const ICON_TONES: IconTone[] = ['indigo', 'violet', 'sky', 'emerald', 'amber', 'rose', 'teal', 'orange', 'slate', 'brand'];

const TONE: Record<IconTone, string> = {
    indigo: 'igr:bg-indigo-50 igr:text-indigo-600 igr:ring-indigo-600/10 igr:dark:bg-indigo-400/12 igr:dark:text-indigo-300 igr:dark:ring-indigo-300/15',
    violet: 'igr:bg-violet-50 igr:text-violet-600 igr:ring-violet-600/10 igr:dark:bg-violet-400/12 igr:dark:text-violet-300 igr:dark:ring-violet-300/15',
    sky: 'igr:bg-sky-50 igr:text-sky-700 igr:ring-sky-700/10 igr:dark:bg-sky-400/12 igr:dark:text-sky-300 igr:dark:ring-sky-300/15',
    emerald: 'igr:bg-emerald-50 igr:text-emerald-700 igr:ring-emerald-700/10 igr:dark:bg-emerald-400/12 igr:dark:text-emerald-300 igr:dark:ring-emerald-300/15',
    amber: 'igr:bg-amber-50 igr:text-amber-700 igr:ring-amber-700/10 igr:dark:bg-amber-400/12 igr:dark:text-amber-300 igr:dark:ring-amber-300/15',
    rose: 'igr:bg-rose-50 igr:text-rose-600 igr:ring-rose-600/10 igr:dark:bg-rose-400/12 igr:dark:text-rose-300 igr:dark:ring-rose-300/15',
    teal: 'igr:bg-teal-50 igr:text-teal-700 igr:ring-teal-700/10 igr:dark:bg-teal-400/12 igr:dark:text-teal-300 igr:dark:ring-teal-300/15',
    orange: 'igr:bg-orange-50 igr:text-orange-700 igr:ring-orange-700/10 igr:dark:bg-orange-400/12 igr:dark:text-orange-300 igr:dark:ring-orange-300/15',
    slate: 'igr:bg-slate-100 igr:text-slate-600 igr:ring-slate-600/10 igr:dark:bg-slate-400/12 igr:dark:text-slate-300 igr:dark:ring-slate-300/15',
    brand: 'igr:brand-gradient igr:text-primary-foreground igr:ring-white/10 igr:shadow-sm',
};

const SIZE = {
    sm: 'igr:size-7 igr:rounded-md igr:[&_svg]:size-3.5',
    md: 'igr:size-9 igr:rounded-lg igr:[&_svg]:size-[18px]',
    lg: 'igr:size-11 igr:rounded-xl igr:[&_svg]:size-5',
} as const;

/** Ícono en mosaico de color (categoría de una sección, estrategia o tarjeta). Decorativo: el texto de al lado lo nombra. */
export function IconTile({ icon: Icon, tone = 'indigo', size = 'md', className }: { icon: LucideIcon; tone?: IconTone; size?: keyof typeof SIZE; className?: string }) {
    return (
        <span aria-hidden className={cn('igr:inline-flex igr:shrink-0 igr:items-center igr:justify-center igr:ring-1 igr:ring-inset', TONE[tone], SIZE[size], className)}>
            <Icon strokeWidth={2} />
        </span>
    );
}
