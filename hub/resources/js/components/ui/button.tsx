import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export const buttonVariants = cva(
    'igr:inline-flex igr:items-center igr:justify-center igr:gap-1.5 igr:whitespace-nowrap igr:rounded-md igr:text-[13px] igr:font-medium igr:transition-colors igr:select-none igr:disabled:pointer-events-none igr:disabled:opacity-50 igr:[&_svg]:size-4 igr:[&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'igr:bg-primary igr:text-primary-foreground igr:shadow-sm igr:hover:bg-primary/90',
                secondary: 'igr:bg-secondary igr:text-secondary-foreground igr:hover:bg-secondary/80',
                outline: 'igr:border igr:border-input igr:bg-background igr:shadow-xs igr:hover:bg-accent igr:hover:text-accent-foreground',
                ghost: 'igr:hover:bg-accent igr:hover:text-accent-foreground',
                destructive: 'igr:bg-destructive igr:text-destructive-foreground igr:shadow-sm igr:hover:bg-destructive/90',
                link: 'igr:text-primary igr:underline-offset-4 igr:hover:underline igr:px-0',
            },
            size: {
                sm: 'igr:h-7 igr:px-2.5 igr:text-xs',
                md: 'igr:h-8 igr:px-3',
                lg: 'igr:h-10 igr:px-4 igr:text-sm',
                icon: 'igr:size-8',
            },
        },
        defaultVariants: { variant: 'default', size: 'md' },
    },
);

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
    loading?: boolean;
}

export function Button({ className, variant, size, asChild = false, loading = false, disabled, children, ...props }: ButtonProps) {
    const Comp = asChild ? Slot : 'button';
    return (
        <Comp className={cn(buttonVariants({ variant, size }), className)} disabled={disabled ?? loading} aria-busy={loading || undefined} {...props}>
            {children}
        </Comp>
    );
}
