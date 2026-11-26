import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ComponentProps, HTMLAttributes } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

/**
 * Cajón lateral (Sheet) sobre el diálogo de Radix: foco atrapado, Escape y clic afuera cierran, y el foco vuelve a lo
 * que lo abrió. `side="right"` (detalle o filtros en el escritorio), `left` (menús) o `bottom` (acciones en el
 * celular, al alcance del pulgar). Mide `100dvh` para que la barra del navegador del celular no tape nada.
 */
export const Sheet = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose = DialogPrimitive.Close;

const SIDES = {
    right: 'igr:inset-y-0 igr:right-0 igr:h-dvh igr:w-full igr:max-w-md igr:border-l igr:sm:rounded-l-2xl',
    left: 'igr:inset-y-0 igr:left-0 igr:h-dvh igr:w-full igr:max-w-sm igr:border-r igr:sm:rounded-r-2xl',
    bottom: 'igr:inset-x-0 igr:bottom-0 igr:max-h-[85dvh] igr:w-full igr:rounded-t-2xl igr:border-t',
} as const;

export function SheetContent({ side = 'right', className, children, ...props }: ComponentProps<typeof DialogPrimitive.Content> & { side?: keyof typeof SIDES }) {
    const { t } = useTranslation();
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-slate-950/40 igr:backdrop-blur-[2px]" />
            <DialogPrimitive.Content
                className={cn(
                    'igr-enter igr:fixed igr:z-50 igr:flex igr:flex-col igr:overflow-y-auto igr:bg-popover igr:text-popover-foreground igr:shadow-lg igr:focus:outline-none',
                    SIDES[side],
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="igr:absolute igr:top-3.5 igr:right-3.5 igr:rounded-md igr:p-1.5 igr:text-muted-foreground igr:transition-colors igr:hover:bg-accent igr:hover:text-foreground"
                    aria-label={t('common.close')}
                >
                    <X className="igr:size-4" aria-hidden />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

export function SheetHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-col igr:gap-1 igr:border-b igr:px-5 igr:py-4 igr:pr-12', className)} {...props} />;
}

export function SheetBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:min-h-0 igr:flex-1 igr:px-5 igr:py-4', className)} {...props} />;
}

export function SheetFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-wrap igr:justify-end igr:gap-2 igr:border-t igr:bg-card igr:px-5 igr:py-3', className)} {...props} />;
}

export function SheetTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
    return <DialogPrimitive.Title className={cn('igr:text-section igr:font-semibold', className)} {...props} />;
}

export function SheetDescription({ className, ...props }: ComponentProps<typeof DialogPrimitive.Description>) {
    return <DialogPrimitive.Description className={cn('igr:text-support igr:text-muted-foreground', className)} {...props} />;
}
