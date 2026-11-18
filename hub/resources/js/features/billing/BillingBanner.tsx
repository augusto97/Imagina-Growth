import { OctagonAlert, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate } from '@/lib/format';
import { billingBanner } from './model';

/**
 * Aviso de gracia, suspensión o fin de prueba en todo el panel (M21). Lo ven todos los
 * roles, porque la suspensión frena los envíos de marketing que preparan; el enlace a
 * «Plan y facturación» solo aparece a quien puede resolverlo. En esa página no se repite.
 */
export function BillingBanner() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { pathname } = useLocation();
    const billing = workspace.billing;
    if (!billing || pathname.endsWith('/facturacion')) {
        return null;
    }
    const banner = billingBanner(billing.status, billing.grace_ends_at, billing.trial_ends_at);
    if (!banner) {
        return null;
    }
    const canResolve = workspace.abilities.includes('manageBilling');

    return (
        <div
            role="status"
            className={cn(
                'igr:mb-4 igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:px-3 igr:py-2.5 igr:text-sm',
                banner.tone === 'critical' ? 'igr:border-destructive/40 igr:bg-destructive/5' : 'igr:border-warning/50 igr:bg-warning/10',
            )}
        >
            {banner.tone === 'critical' ? (
                <OctagonAlert aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" />
            ) : (
                <TriangleAlert aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" />
            )}
            <div className="igr:min-w-0 igr:flex-1">
                <p className="igr:font-medium">{t(`billing.banner.${banner.kind}.title`)}</p>
                <p className="igr:text-muted-foreground">
                    {t(`billing.banner.${banner.kind}.body`, { date: formatDate(banner.until, workspace.locale, workspace.timezone) })}
                </p>
            </div>
            {canResolve ? (
                <Link to={`/w/${workspace.id}/facturacion`} className="igr:shrink-0 igr:text-sm igr:font-medium igr:text-primary igr:underline-offset-4 igr:hover:underline">
                    {t('billing.banner.cta')}
                </Link>
            ) : null}
        </div>
    );
}
