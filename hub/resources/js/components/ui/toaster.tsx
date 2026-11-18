import { CircleAlert, CircleCheck, Info, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { useToasts } from '@/stores/toast';

export function Toaster() {
    const { toasts, dismiss } = useToasts();
    const { t } = useTranslation();
    return (
        <div aria-live="polite" className="igr:pointer-events-none igr:fixed igr:bottom-4 igr:right-4 igr:z-[60] igr:flex igr:w-80 igr:flex-col igr:gap-2">
            {toasts.map((item) => {
                const Icon = item.tone === 'success' ? CircleCheck : item.tone === 'error' ? CircleAlert : Info;
                return (
                    <div
                        key={item.id}
                        role="status"
                        className="igr:pointer-events-auto igr:flex igr:items-start igr:gap-2.5 igr:rounded-lg igr:border igr:bg-popover igr:p-3 igr:text-popover-foreground igr:shadow-lg"
                    >
                        <Icon
                            className={cn(
                                'igr:mt-0.5 igr:size-4 igr:shrink-0',
                                item.tone === 'success' && 'igr:text-success',
                                item.tone === 'error' && 'igr:text-destructive',
                                item.tone === 'default' && 'igr:text-muted-foreground',
                            )}
                        />
                        <div className="igr:min-w-0 igr:flex-1">
                            <p className="igr:text-[13px] igr:font-medium">{item.title}</p>
                            {item.description ? <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground igr:break-words">{item.description}</p> : null}
                        </div>
                        <button
                            type="button"
                            onClick={() => dismiss(item.id)}
                            aria-label={t('common.close')}
                            className="igr:rounded igr:p-0.5 igr:text-muted-foreground igr:hover:text-foreground"
                        >
                            <X className="igr:size-3.5" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
