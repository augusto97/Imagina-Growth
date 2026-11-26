import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { SiteHealthCause } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ConnectionAlertsNotice } from '@/features/connections/AlertsNotice';
import { causeTitle } from '@/features/sites/SiteHealth';
import type { HealthNotice } from './types';

/*
 * Avisos de salud compactos (docs/diseno/rediseno-v2.md §5.6): solo si hay algo. Los sitios que no están al día (con
 * su causa principal y adónde ir a resolverla) y las conexiones por vencer o caídas, con «Reconectar».
 */
export function HealthNotices({ health, base }: { health: HealthNotice[]; base: string }) {
    const { t } = useTranslation();
    return (
        <div className="igr:space-y-2">
            {health.map((notice) => {
                const cause = notice.causes[0];
                return (
                    <Callout
                        key={notice.site_id}
                        tone={notice.state === 'no_data' ? 'warning' : 'info'}
                        title={t(`home.health.${notice.state}`, { host: notice.host })}
                        action={
                            <Button asChild size="sm" variant="outline">
                                <Link to={`${base}/sitios/${notice.site_id}`}>
                                    {t('sites.health.whatToDo')}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        }
                    >
                        {cause ? causeTitle(t, cause as unknown as SiteHealthCause) : null}
                    </Callout>
                );
            })}
            <ConnectionAlertsNotice />
        </div>
    );
}
