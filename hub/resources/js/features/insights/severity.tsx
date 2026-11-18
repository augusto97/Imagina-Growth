import { CircleAlert, Info, OctagonAlert, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

/*
 * Severidad de un hallazgo con los colores de estado reservados (skill dataviz):
 * crítico, advertencia y oportunidad, siempre con icono y texto.
 */

export const SEVERITIES = ['critical', 'warning', 'opportunity', 'info'] as const;
export type Severity = (typeof SEVERITIES)[number];

const STYLE: Record<Severity, { icon: LucideIcon; color: string }> = {
    critical: { icon: OctagonAlert, color: '#d03b3b' },
    warning: { icon: CircleAlert, color: '#fab219' },
    opportunity: { icon: TrendingUp, color: '#0ca30c' },
    info: { icon: Info, color: '#898781' },
};

export function isSeverity(value: string): value is Severity {
    return (SEVERITIES as readonly string[]).includes(value);
}

export function SeverityBadge({ severity, className }: { severity: string; className?: string }) {
    const { t } = useTranslation();
    const key: Severity = isSeverity(severity) ? severity : 'info';
    const { icon: Icon, color } = STYLE[key];
    return (
        <span className={cn('igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1 igr:rounded-full igr:border igr:px-2 igr:py-0.5 igr:text-[11px] igr:font-medium igr:leading-none igr:whitespace-nowrap', className)}>
            <Icon className="igr:size-3.5" style={{ color }} aria-hidden />
            {t(`insights.severity.${key}`)}
        </span>
    );
}
