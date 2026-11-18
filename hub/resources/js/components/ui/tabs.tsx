import * as TabsPrimitive from '@radix-ui/react-tabs';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/cn';

export const Tabs = TabsPrimitive.Root;

export function TabsList({ className, ...props }: ComponentProps<typeof TabsPrimitive.List>) {
    return <TabsPrimitive.List className={cn('igr:inline-flex igr:items-center igr:gap-1 igr:border-b igr:w-full', className)} {...props} />;
}

export function TabsTrigger({ className, ...props }: ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            className={cn(
                'igr:-mb-px igr:border-b-2 igr:border-transparent igr:px-2.5 igr:py-2 igr:text-[13px] igr:font-medium igr:text-muted-foreground igr:transition-colors igr:hover:text-foreground igr:data-[state=active]:border-primary igr:data-[state=active]:text-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function TabsContent({ className, ...props }: ComponentProps<typeof TabsPrimitive.Content>) {
    return <TabsPrimitive.Content className={cn('igr:pt-4 igr:focus-visible:outline-none', className)} {...props} />;
}
