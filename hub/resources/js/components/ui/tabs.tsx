import * as TabsPrimitive from '@radix-ui/react-tabs';
import { useLayoutEffect, useRef, type ComponentProps } from 'react';
import { cn } from '@/lib/cn';

export const Tabs = TabsPrimitive.Root;

/**
 * Pestañas en una sola línea que se desplaza de lado cuando no caben (celular), sin barra de desplazamiento visible y
 * con la pestaña activa siempre a la vista. La línea de abajo es una sombra interior: un borde se recortaría al desplazar.
 */
export function TabsList({ className, ...props }: ComponentProps<typeof TabsPrimitive.List>) {
    const ref = useRef<HTMLDivElement>(null);
    useLayoutEffect(() => {
        const list = ref.current;
        const active = list?.querySelector<HTMLElement>('[role="tab"][data-state="active"]');
        if (!list || !active || list.scrollWidth <= list.clientWidth) {
            return;
        }
        const left = active.getBoundingClientRect().left - list.getBoundingClientRect().left + list.scrollLeft;
        if (left < list.scrollLeft) {
            list.scrollLeft = left;
        } else if (left + active.offsetWidth > list.scrollLeft + list.clientWidth) {
            list.scrollLeft = left + active.offsetWidth - list.clientWidth;
        }
    });
    return (
        <TabsPrimitive.List
            ref={ref}
            className={cn('igr:flex igr:w-full igr:items-center igr:gap-1 igr:overflow-x-auto igr:shadow-[inset_0_-1px_0_var(--border)] igr:scrollbar-none', className)}
            {...props}
        />
    );
}

export function TabsTrigger({ className, ...props }: ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            className={cn(
                'igr:shrink-0 igr:whitespace-nowrap igr:border-b-2 igr:border-transparent igr:px-3 igr:py-2.5 igr:text-[13px] igr:font-medium igr:text-muted-foreground igr:transition-colors igr:hover:text-foreground igr:data-[state=active]:border-primary igr:data-[state=active]:text-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function TabsContent({ className, ...props }: ComponentProps<typeof TabsPrimitive.Content>) {
    return <TabsPrimitive.Content className={cn('igr:pt-5 igr:focus-visible:outline-none', className)} {...props} />;
}
