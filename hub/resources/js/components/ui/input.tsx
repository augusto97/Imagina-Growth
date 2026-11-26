import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * Campos del sistema v2: borde con 3:1 de contraste (se reconocen como campos), anillo de foco de marca y 36 px de alto
 * (igual que los botones `md`). En error, `aria-invalid` pinta el borde.
 */
const field =
    'igr:flex igr:w-full igr:rounded-md igr:border igr:border-input igr:bg-card igr:px-3 igr:text-[13px] igr:shadow-xs igr:transition-[border-color,box-shadow] igr:placeholder:text-muted-foreground igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/25 igr:focus-visible:border-ring igr:disabled:cursor-not-allowed igr:disabled:opacity-50 igr:aria-invalid:border-destructive igr:aria-invalid:ring-destructive/20';

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={cn(field, 'igr:h-9', className)} {...props} />;
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cn(field, 'igr:min-h-20 igr:py-2', className)} {...props} />;
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select className={cn(field, 'igr:h-9 igr:pr-7', className)} {...props}>
            {children}
        </select>
    );
}
