import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

const field =
    'igr:flex igr:w-full igr:rounded-md igr:border igr:border-input igr:bg-background igr:px-2.5 igr:text-[13px] igr:shadow-xs igr:transition-colors igr:placeholder:text-muted-foreground igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring/40 igr:focus-visible:border-ring igr:disabled:cursor-not-allowed igr:disabled:opacity-50 igr:aria-invalid:border-destructive';

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={cn(field, 'igr:h-8', className)} {...props} />;
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cn(field, 'igr:min-h-20 igr:py-2', className)} {...props} />;
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select className={cn(field, 'igr:h-8 igr:pr-7', className)} {...props}>
            {children}
        </select>
    );
}
