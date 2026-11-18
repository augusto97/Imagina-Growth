import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div aria-hidden className={cn('igr-skeleton igr:rounded-md', className)} {...props} />;
}
