import { CircleHelp } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { Tooltip } from './tooltip';

/**
 * La ayuda corta «?» de cada métrica (rediseño v2 §7): un botón que muestra la explicación al pasar el puntero o al
 * enfocarlo con el teclado. `label` nombra lo que explica para los lectores de pantalla («Cómo se calcula Ventas»).
 */
export function HelpTip({ content, label, className }: { content: ReactNode; label?: string; className?: string }) {
    const { t } = useTranslation();
    return (
        <Tooltip content={content}>
            <button
                type="button"
                aria-label={label ?? t('ui.help')}
                className={cn('igr:inline-flex igr:shrink-0 igr:cursor-help igr:rounded-full igr:text-muted-foreground igr:transition-colors igr:hover:text-foreground', className)}
            >
                <CircleHelp className="igr:size-3.5" aria-hidden />
            </button>
        </Tooltip>
    );
}
