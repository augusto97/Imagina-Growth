import * as Menu from '@radix-ui/react-dropdown-menu';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/cn';

export const DropdownMenu = Menu.Root;
export const DropdownMenuTrigger = Menu.Trigger;
export const DropdownMenuGroup = Menu.Group;

export function DropdownMenuContent({ className, sideOffset = 6, ...props }: ComponentProps<typeof Menu.Content>) {
    return (
        <Menu.Portal>
            <Menu.Content
                sideOffset={sideOffset}
                className={cn(
                    'igr:z-50 igr:min-w-48 igr:overflow-hidden igr:rounded-lg igr:border igr:bg-popover igr:p-1 igr:text-popover-foreground igr:shadow-lg',
                    className,
                )}
                {...props}
            />
        </Menu.Portal>
    );
}

export function DropdownMenuItem({ className, ...props }: ComponentProps<typeof Menu.Item>) {
    return (
        <Menu.Item
            className={cn(
                'igr:flex igr:cursor-default igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-[13px] igr:outline-none igr:select-none igr:data-[highlighted]:bg-accent igr:data-[disabled]:opacity-50 igr:[&_svg]:size-4 igr:[&_svg]:text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function DropdownMenuLabel({ className, ...props }: ComponentProps<typeof Menu.Label>) {
    return <Menu.Label className={cn('igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground', className)} {...props} />;
}

export function DropdownMenuSeparator({ className, ...props }: ComponentProps<typeof Menu.Separator>) {
    return <Menu.Separator className={cn('igr:-mx-1 igr:my-1 igr:h-px igr:bg-border', className)} {...props} />;
}
