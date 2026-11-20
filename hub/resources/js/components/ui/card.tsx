import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:rounded-lg igr:border igr:bg-card igr:text-card-foreground igr:shadow-xs', className)} {...props} />;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-col igr:gap-1 igr:p-4 igr:pb-2', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h3 className={cn('igr:text-sm igr:font-semibold igr:leading-tight', className)} {...props} />;
}

export function CardDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cn('igr:text-[13px] igr:text-muted-foreground', className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:p-4 igr:pt-2', className)} {...props} />;
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:px-4 igr:py-3', className)} {...props} />;
}
