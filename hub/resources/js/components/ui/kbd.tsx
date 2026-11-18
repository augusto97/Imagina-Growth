import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export function Kbd({ className, ...props }: HTMLAttributes<HTMLElement>) {
    return (
        <kbd
            className={cn(
                'igr:inline-flex igr:h-5 igr:min-w-5 igr:items-center igr:justify-center igr:rounded igr:border igr:bg-muted igr:px-1 igr:font-sans igr:text-[10px] igr:font-medium igr:text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}
