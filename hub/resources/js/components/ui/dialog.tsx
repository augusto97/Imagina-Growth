import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ComponentProps, HTMLAttributes } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export function DialogContent({ className, children, ...props }: ComponentProps<typeof DialogPrimitive.Content>) {
    const { t } = useTranslation();
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-black/40 igr:backdrop-blur-[1px]" />
            <DialogPrimitive.Content
                className={cn(
                    'igr:fixed igr:left-1/2 igr:top-[12vh] igr:z-50 igr:w-[calc(100%-2rem)] igr:max-w-lg igr:-translate-x-1/2 igr:rounded-xl igr:border igr:bg-popover igr:p-5 igr:text-popover-foreground igr:shadow-xl igr:focus:outline-none',
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="igr:absolute igr:right-3 igr:top-3 igr:rounded-md igr:p-1 igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground"
                    aria-label={t('common.close')}
                >
                    <X className="igr:size-4" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:mb-4 igr:flex igr:flex-col igr:gap-1 igr:pr-6', className)} {...props} />;
}

export function DialogFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:mt-5 igr:flex igr:justify-end igr:gap-2', className)} {...props} />;
}

export function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
    return <DialogPrimitive.Title className={cn('igr:text-base igr:font-semibold', className)} {...props} />;
}

export function DialogDescription({ className, ...props }: ComponentProps<typeof DialogPrimitive.Description>) {
    return <DialogPrimitive.Description className={cn('igr:text-[13px] igr:text-muted-foreground', className)} {...props} />;
}
