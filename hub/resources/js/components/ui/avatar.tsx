import { cn } from '@/lib/cn';

/**
 * Avatar de una persona con sus iniciales (rediseño v2 §7): el color sale del nombre (o del id), siempre el mismo para
 * la misma persona, con tintes de los mosaicos que pasan AA en claro y en oscuro. Decorativo: el nombre va al lado.
 */

const TONES = [
    'igr:bg-indigo-50 igr:text-indigo-700 igr:dark:bg-indigo-400/15 igr:dark:text-indigo-200',
    'igr:bg-violet-50 igr:text-violet-700 igr:dark:bg-violet-400/15 igr:dark:text-violet-200',
    'igr:bg-sky-50 igr:text-sky-800 igr:dark:bg-sky-400/15 igr:dark:text-sky-200',
    'igr:bg-emerald-50 igr:text-emerald-800 igr:dark:bg-emerald-400/15 igr:dark:text-emerald-200',
    'igr:bg-amber-50 igr:text-amber-800 igr:dark:bg-amber-400/15 igr:dark:text-amber-200',
    'igr:bg-rose-50 igr:text-rose-700 igr:dark:bg-rose-400/15 igr:dark:text-rose-200',
    'igr:bg-teal-50 igr:text-teal-800 igr:dark:bg-teal-400/15 igr:dark:text-teal-200',
    'igr:bg-orange-50 igr:text-orange-800 igr:dark:bg-orange-400/15 igr:dark:text-orange-200',
] as const;

const SIZES = {
    sm: 'igr:size-7 igr:text-[11px]',
    md: 'igr:size-9 igr:text-xs',
    lg: 'igr:size-12 igr:text-base',
    xl: 'igr:size-14 igr:text-lg',
} as const;

/** Hasta dos iniciales: la primera palabra y la última («Ana María Ruiz» → «AR»); sin nombre, «·». */
export function initialsOf(name: string | null | undefined): string {
    const words = (name ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) {
        return '·';
    }
    const first = words[0]?.[0] ?? '';
    const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '';
    return (first + last).toLocaleUpperCase('es');
}

/** El tono del avatar: un número estable a partir del nombre o del id, para que no cambie de color al volver. */
export function avatarToneIndex(seed: string | null | undefined): number {
    let hash = 0;
    for (const ch of seed ?? '') {
        hash = (hash * 31 + ch.charCodeAt(0)) | 0;
    }
    return Math.abs(hash) % TONES.length;
}

export function Avatar({ name, seed, size = 'md', className }: { name: string | null | undefined; seed?: string; size?: keyof typeof SIZES; className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'igr:inline-flex igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:font-semibold igr:tracking-tight igr:select-none',
                TONES[avatarToneIndex(seed ?? name)],
                SIZES[size],
                className,
            )}
        >
            {initialsOf(name)}
        </span>
    );
}
