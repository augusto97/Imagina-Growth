import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

const badgeVariants = cva(
    'igr:inline-flex igr:items-center igr:gap-1 igr:rounded-full igr:border igr:px-2 igr:py-0.5 igr:text-[11px] igr:font-medium igr:leading-none igr:whitespace-nowrap',
    {
        variants: {
            variant: {
                default: 'igr:border-transparent igr:bg-primary/10 igr:text-primary',
                secondary: 'igr:border-transparent igr:bg-secondary igr:text-secondary-foreground',
                outline: 'igr:text-muted-foreground',
                success: 'igr:border-transparent igr:bg-success/12 igr:text-success',
                warning: 'igr:border-transparent igr:bg-warning/15 igr:text-warning',
                destructive: 'igr:border-transparent igr:bg-destructive/12 igr:text-destructive',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export function StatusDot({ tone }: { tone: 'success' | 'warning' | 'destructive' | 'muted' }) {
    const color = {
        success: 'igr:bg-success',
        warning: 'igr:bg-warning',
        destructive: 'igr:bg-destructive',
        muted: 'igr:bg-muted-foreground/50',
    }[tone];
    return <span aria-hidden className={cn('igr:inline-block igr:size-1.5 igr:rounded-full', color)} />;
}
