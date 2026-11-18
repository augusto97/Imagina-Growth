import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Tailwind v4 con prefijo propio: las clases son igr:flex, igr:hover:bg-accent…
const twMerge = extendTailwindMerge({ prefix: 'igr' });

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
