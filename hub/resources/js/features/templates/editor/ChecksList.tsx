import { CircleAlert, CircleCheck, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { TemplateCheck } from '../api';

/**
 * Revisiones de la plantilla: las del servidor traen el mensaje en español; las
 * del editor en vivo traen solo el código y se traducen aquí.
 */
export function ChecksList({ checks, live = false }: { checks: TemplateCheck[]; live?: boolean }) {
    const { t } = useTranslation();
    if (checks.length === 0) {
        return (
            <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:text-success">
                <CircleCheck className="igr:size-4" />
                {t('templates.checks.ok')}
            </p>
        );
    }
    const sorted = [...checks].sort((a, b) => (a.level === b.level ? 0 : a.level === 'error' ? -1 : 1));
    return (
        <ul className="igr:space-y-1.5" aria-label={t('templates.checks.title')}>
            {sorted.map((c) => (
                <li key={c.code} className="igr:flex igr:items-start igr:gap-1.5 igr:text-[13px]">
                    {c.level === 'error' ? (
                        <CircleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" aria-label={t('templates.checks.error')} />
                    ) : (
                        <TriangleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-label={t('templates.checks.warning')} />
                    )}
                    <span>{live ? t(`templates.checks.codes.${c.code}`) : c.message}</span>
                </li>
            ))}
        </ul>
    );
}
