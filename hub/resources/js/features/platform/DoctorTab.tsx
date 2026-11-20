import { RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { type DoctorStatus, sortChecks, statusVariant, usePlatformDoctor } from './doctor-api';

/** Plataforma → Instalación: qué falta configurar y cómo (lo mismo que `php artisan igr:doctor`). */
export function DoctorTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const doctor = usePlatformDoctor(true);
    if (doctor.isError) return <ErrorState error={doctor.error} onRetry={() => void doctor.refetch()} />;
    if (doctor.isPending) return <Skeleton className="igr:h-64" />;
    const report = doctor.data;
    const statuses: DoctorStatus[] = ['error', 'warning', 'info', 'ok'];

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                    <div className="igr:space-y-1.5">
                        <CardTitle className="igr:flex igr:items-center igr:gap-2">
                            {t('platform.doctor.title')}
                            <Badge variant={statusVariant(report.status)}>{t(`platform.doctor.overall.${report.status}`)}</Badge>
                        </CardTitle>
                        <CardDescription>{t('platform.doctor.description')}</CardDescription>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => void doctor.refetch()} disabled={doctor.isFetching}>
                        <RefreshCw className="igr:size-4" />
                        {t('platform.doctor.recheck')}
                    </Button>
                </CardHeader>
                <CardContent className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-sm">
                    {statuses.map((s) =>
                        report.counts[s] ? (
                            <Badge key={s} variant={statusVariant(s)}>
                                {t(`platform.doctor.count.${s}`, { count: report.counts[s] })}
                            </Badge>
                        ) : null,
                    )}
                    <span className="igr:text-muted-foreground">{t('platform.doctor.checkedAt', { date: formatDateTime(report.checked_at, workspace.locale) })}</span>
                </CardContent>
            </Card>
            {report.groups.map((group) => (
                <Card key={group.key}>
                    <CardHeader>
                        <CardTitle className="igr:text-base">{group.name}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="igr:divide-y igr:divide-border">
                            {sortChecks(group.checks).map((check) => (
                                <li key={check.key} className="igr:flex igr:items-start igr:gap-3 igr:py-2.5 igr:first:pt-0 igr:last:pb-0">
                                    <Badge variant={statusVariant(check.status)} className="igr:mt-0.5 igr:shrink-0">
                                        {t(`platform.doctor.status.${check.status}`)}
                                    </Badge>
                                    <div className="igr:min-w-0 igr:space-y-1 igr:text-sm">
                                        <p className="igr:font-medium">{check.title}</p>
                                        {check.status !== 'ok' && check.detail !== '' ? <p className="igr:text-muted-foreground">{check.detail}</p> : null}
                                        {check.items.length > 0 ? (
                                            <ul className="igr:list-disc igr:space-y-0.5 igr:pl-5 igr:text-muted-foreground">
                                                {check.items.map((item) => (
                                                    <li key={item} className="igr:break-words">
                                                        {item}
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
