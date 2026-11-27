import { AlertTriangle, ArrowRight, Bell, CheckCircle2, Mail, MessageCircle, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useBookingNoticeSetup, type BookingNoticeSetup } from '@/features/notices/api';
import { useNoticeCenter } from '@/features/notices/model';

/** Dónde se arregla cada cosa que falta (ruta dentro del espacio de trabajo). */
const FIX_PATH: Record<string, string> = {
    connect_whatsapp: 'conexiones?tarjeta=whatsapp',
    connect_email: 'conexiones?tarjeta=email',
    platform_mail: 'plataforma?pestana=ajustes&seccion=mail',
};

/**
 * «Así avisamos» (lote 0.4.0, docs/avisos.md §4): cómo le llega la confirmación al cliente y a quién del equipo se le
 * avisa de cada cita, con lo que falta y el botón para resolverlo.
 */
export function NoticeSetupCard({ onRequireEmail, onChooseTemplate, saving }: { onRequireEmail: () => void; onChooseTemplate: () => void; saving: boolean }) {
    const { t, i18n } = useTranslation();
    const { workspace, me } = useWorkspace();
    const setup = useBookingNoticeSetup(workspace.id);
    const openNotices = useNoticeCenter((s) => s.setOpen);

    if (setup.isPending) {
        return <Skeleton className="igr:h-48 igr:lg:col-span-2" />;
    }
    if (setup.isError) {
        return null;
    }
    const s: BookingNoticeSetup = setup.data;
    const c = s.customer;
    const whatsappOk = c.whatsapp === 'ready' || c.whatsapp === 'unofficial';
    const emailOk = c.email === 'own' || c.email === 'platform';
    const fixes = s.fixes.filter((f) => f !== 'nothing_reaches' && (f !== 'platform_mail' || me.user.is_platform_admin));

    return (
        <Card className="igr:lg:col-span-2">
            <CardHeader>
                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                    <Bell className="igr:size-4 igr:text-primary" aria-hidden />
                    {t('notices.setup.title')}
                </CardTitle>
                <CardDescription>{t('notices.setup.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4 igr:text-[13px]">
                {s.fixes.includes('nothing_reaches') ? <Callout tone="danger" title={t('notices.setup.nothing')} /> : null}
                <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                    <section className="igr:space-y-2">
                        <h3 className="igr:font-semibold">{t('notices.setup.customer')}</h3>
                        <Line ok={whatsappOk} icon={MessageCircle}>
                            {t(`notices.setup.whatsapp.${c.whatsapp}`, { number: c.whatsapp_number ?? '' })}
                        </Line>
                        <Line ok={emailOk} icon={Mail}>
                            {c.email === 'platform'
                                ? c.reply_to
                                    ? t('notices.setup.email.platform', { from: c.email_from ?? '', replyTo: c.reply_to })
                                    : t('notices.setup.email.platformNoReply', { from: c.email_from ?? '' })
                                : t(`notices.setup.email.${c.email}`, { from: c.email_from ?? '' })}
                        </Line>
                        <p className="igr:text-muted-foreground">
                            {t(`notices.setup.askEmail.${!c.ask_email ? 'hidden' : c.require_email ? 'required' : 'optional'}`)}{' '}
                            {c.reminders.length > 0 ? t('notices.setup.reminders', { hours: new Intl.ListFormat(i18n.language, { type: 'conjunction' }).format(c.reminders.map(String)) }) : null}
                        </p>
                        {s.fixes.includes('require_email') ? <p className="igr:text-muted-foreground">{t('notices.setup.requireEmailHint')}</p> : null}
                    </section>
                    <section className="igr:space-y-2">
                        <h3 className="igr:font-semibold">{t('notices.setup.team')}</h3>
                        {s.team.people.length === 0 ? (
                            <Line ok={false} icon={Users}>
                                {t('notices.setup.teamNone')}
                            </Line>
                        ) : (
                            <>
                                <p className="igr:text-muted-foreground">{t('notices.setup.teamIntro')}</p>
                                <ul className="igr:space-y-1">
                                    {s.team.people.map((p, i) => (
                                        <li key={`${p.name}-${i}`} className="igr:flex igr:items-start igr:gap-2">
                                            <Users className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                            <span>
                                                <span className="igr:font-medium">{p.name}</span>
                                                <span className="igr:text-muted-foreground">
                                                    {' '}
                                                    · {t(`notices.setup.scope.${p.scope}`)} · {p.channels.map((ch) => t(`notices.prefs.channels.${ch}`)).join(', ')}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {!s.team.platform_mail ? <p className="igr:text-warning">{t('notices.setup.platformMailOff')}</p> : null}
                        <Button variant="link" className="igr:h-auto igr:p-0" onClick={() => openNotices(true)}>
                            {t('notices.setup.myNotices')}
                        </Button>
                    </section>
                </div>
                {fixes.length > 0 ? (
                    <div className="igr:flex igr:flex-wrap igr:gap-2 igr:border-t igr:pt-3">
                        {fixes.map((fix) =>
                            fix === 'require_email' ? (
                                <Button key={fix} size="sm" variant="outline" onClick={onRequireEmail} loading={saving}>
                                    {t(`notices.setup.fixes.${fix}`)}
                                </Button>
                            ) : fix === 'choose_template' ? (
                                <Button key={fix} size="sm" variant="outline" onClick={onChooseTemplate}>
                                    {t(`notices.setup.fixes.${fix}`)}
                                </Button>
                            ) : FIX_PATH[fix] ? (
                                <Button key={fix} size="sm" variant="outline" asChild>
                                    <Link to={`/w/${workspace.id}/${FIX_PATH[fix]}`}>
                                        {t(`notices.setup.fixes.${fix}`)}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            ) : null,
                        )}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function Line({ ok, icon: Icon, children }: { ok: boolean; icon: typeof Mail; children: ReactNode }) {
    return (
        <p className="igr:flex igr:items-start igr:gap-2">
            <Icon className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
            <span className="igr:min-w-0 igr:flex-1">{children}</span>
            {ok ? (
                <CheckCircle2 className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
            ) : (
                <AlertTriangle className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden />
            )}
        </p>
    );
}
