import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';

export const TooltipProvider = TooltipPrimitive.Provider;

export function Tooltip({ content, children, side = 'top' }: { content: ReactNode; children: ReactNode; side?: 'top' | 'bottom' | 'left' | 'right' }) {
    return (
        <TooltipPrimitive.Root delayDuration={200}>
            <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
            <TooltipPrimitive.Portal>
                <TooltipPrimitive.Content
                    side={side}
                    sideOffset={6}
                    className="igr:z-50 igr:max-w-xs igr:rounded-md igr:bg-foreground igr:px-2.5 igr:py-1.5 igr:text-xs igr:leading-relaxed igr:text-background igr:shadow-md"
                >
                    {content}
                    <TooltipPrimitive.Arrow className="igr:fill-foreground" />
                </TooltipPrimitive.Content>
            </TooltipPrimitive.Portal>
        </TooltipPrimitive.Root>
    );
}
