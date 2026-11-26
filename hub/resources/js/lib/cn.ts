import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Tailwind v4 con prefijo propio: las clases son igr:flex, igr:hover:bg-accent…
// La escala tipográfica propia (resources/css/app.css: igr:text-title, text-support…) es de tamaño, no de color: sin
// declararla, tailwind-merge la confunde con un color y la borra si la misma clase trae igr:text-muted-foreground.
const twMerge = extendTailwindMerge({
    prefix: 'igr',
    extend: { classGroups: { 'font-size': [{ text: ['title', 'section', 'body', 'support', 'caption', 'figure', 'hero'] }] } },
});

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
