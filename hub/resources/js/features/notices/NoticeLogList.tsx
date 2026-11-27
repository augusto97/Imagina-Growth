import { ArrowRight, Bell, Globe, Mail, MessageCircle, Smartphone } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Callout } from '@/components/ui/callout';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import type { NoticeLogRow } from './api';
import { customerReached, noticeTone, reasonFix, splitByAudience } from './model';

const CHANNEL_ICON = { whatsapp: MessageCircle, email: Mail, in_app: Bell, push: Globe } as const;

/**
 * La sección «Avisos» de una cita o un lead (lote 0.4.0): qué se le envió al cliente y al equipo, por qué canal, en
 * qué estado y, si no salió, el motivo en palabras con el botón para arreglarlo.
 */
export function NoticeLogList({ rows, loading = false, error = false, withCustomerCheck = true }: { rows: NoticeLogRow[] | undefined; loading?: boolean; error?: boolean; withCustomerCheck?: boolean }) {
    const { t } = useTranslation();
    if (loading) {
        return (
            <div className="igr:space-y-2" aria-busy>
                <Skeleton className="igr:h-12" />
                <Skeleton className="igr:h-12" />
            </div>
        );
    }
    if (error) {
        return <Callout tone="danger" title={t('notices.log.error')} />;
    }
    const all = rows ?? [];
    if (all.length === 0) {
        return <p className="igr:text-support igr:text-muted-foreground">{t('notices.log.empty')}</p>;
    }
    const { customer, team } = splitByAudience(all);

    return (
        <div className="igr:space-y-4">
            {withCustomerCheck && customer.length > 0 && !customerReached(all) ? <Callout tone="warning" title={t('notices.log.nothingReached')} /> : null}
            {customer.length > 0 ? <Group title={t('notices.log.customer')} rows={customer} /> : null}
            {team.length > 0 ? <Group title={t('notices.log.team')} rows={team} /> : null}
        </div>
    );
}

function Group({ title, rows }: { title: string; rows: NoticeLogRow[] }) {
    return (
        <section>
            <h3 className="igr:mb-1.5 igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{title}</h3>
            <ul className="igr:divide-y igr:rounded-lg igr:border">
                {rows.map((row) => (
                    <Row key={row.id} row={row} />
                ))}
            </ul>
        </section>
    );
}

function Row({ row }: { row: NoticeLogRow }) {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const Icon = row.channel !== null ? CHANNEL_ICON[row.channel] : Smartphone;
    const tone = noticeTone(row.status);
    const failed = row.status !== 'sent' && row.status !== 'queued';
    const fix = failed ? reasonFix(row.reason, row.channel, me.user.is_platform_admin) : null;
    const reason = row.reason !== null ? t(`notices.log.reason.${row.reason}`, { defaultValue: t('notices.log.reason.default') }) : null;

    return (
        <li className="igr:flex igr:items-start igr:gap-3 igr:px-3 igr:py-2.5">
            <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
            <div className="igr:min-w-0 igr:flex-1">
                <p className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1 igr:text-support">
                    <span className="igr:font-medium">{t(`notices.log.event.${row.event}`, { defaultValue: row.event })}</span>
                    <span className="igr:text-muted-foreground">· {t(`notices.log.channel.${row.channel ?? 'none'}`)}</span>
                    {row.recipient ? <span className="igr:truncate igr:text-muted-foreground">· {row.recipient}</span> : null}
                    <Badge variant={tone}>{t(`notices.log.status.${row.status}`)}</Badge>
                </p>
                {reason !== null && (failed || row.reason === 'in_chat') ? <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{reason}</p> : null}
                {row.detail ? <p className="igr:mt-0.5 igr:text-caption igr:break-words igr:text-muted-foreground">{row.detail}</p> : null}
                {row.created_at ? <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{formatDateTime(row.created_at, workspace.locale, workspace.timezone)}</p> : null}
            </div>
            {fix !== null ? (
                <Link to={`/w/${workspace.id}/${fix}`} className="igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1 igr:text-support igr:font-medium igr:text-primary igr:hover:underline">
                    {t('notices.log.fix')}
                    <ArrowRight className="igr:size-3.5" aria-hidden />
                </Link>
            ) : null}
        </li>
    );
}
