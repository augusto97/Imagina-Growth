import { Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAgendaMutations } from './api';
import type { BookingOverview, BookingSettings } from './types';

const CHANNELS = ['whatsapp', 'email', 'sms'] as const;
const TEMPLATES = ['confirmation', 'reminder', 'rescheduled', 'cancelled'] as const;

/** La página pública de reservas: enlace, QR, botón para el sitio y ajustes de captura y recordatorios. */
export function PageTab({ data }: { data: BookingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { saveSettings } = useAgendaMutations(workspace.id);
    const [form, setForm] = useState<BookingSettings>(data.settings);
    const set = <K extends keyof BookingSettings>(k: K, v: BookingSettings[K]) => setForm((f) => ({ ...f, [k]: v }));
    const copy = (text: string) => void navigator.clipboard.writeText(text).then(() => toast.success(t('common.copied')));
    const firstSlug = data.services.find((s) => s.active)?.slug;
    const shortcode = firstSlug ? `[igr_booking service="${firstSlug}"]` : '[igr_booking]';
    const disabled = !data.can_manage;
    const save = () =>
        saveSettings.mutate(
            { ...form, page_title: form.page_title?.trim() || null, intro: form.intro?.trim() || null },
            { onSuccess: () => toast.success(t('agenda.page.saved')), onError: (e) => toast.error(errorMessage(e, t)) },
        );

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
            <Card>
                <CardHeader>
                    <CardTitle>{t('agenda.page.settings')}</CardTitle>
                    <CardDescription>{t('agenda.page.settingsHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3 igr:text-[13px]">
                    <label className="igr:flex igr:items-center igr:gap-2">
                        <input type="checkbox" checked={form.enabled} disabled={disabled} onChange={(e) => set('enabled', e.target.checked)} />
                        {t('agenda.page.enabled')}
                    </label>
                    <div>
                        <Label htmlFor="page-title">{t('agenda.page.pageTitle')}</Label>
                        <Input id="page-title" className="igr:mt-1" value={form.page_title ?? ''} maxLength={120} disabled={disabled} placeholder={t('agenda.page.pageTitlePlaceholder')} onChange={(e) => set('page_title', e.target.value)} />
                    </div>
                    <div>
                        <Label htmlFor="page-intro">{t('agenda.page.intro')}</Label>
                        <Textarea id="page-intro" className="igr:mt-1" rows={2} value={form.intro ?? ''} maxLength={600} disabled={disabled} onChange={(e) => set('intro', e.target.value)} />
                    </div>
                    <div className="igr:flex igr:flex-wrap igr:gap-4">
                        <label className="igr:flex igr:items-center igr:gap-2">
                            <input type="checkbox" checked={form.ask_email} disabled={disabled} onChange={(e) => set('ask_email', e.target.checked)} />
                            {t('agenda.page.askEmail')}
                        </label>
                        <label className="igr:flex igr:items-center igr:gap-2">
                            <input type="checkbox" checked={form.require_email} disabled={disabled} onChange={(e) => set('require_email', e.target.checked)} />
                            {t('agenda.page.requireEmail')}
                        </label>
                    </div>
                    <fieldset>
                        <legend className="igr:font-medium">{t('agenda.page.consents')}</legend>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.page.consentsHelp')}</p>
                        <div className="igr:mt-1 igr:flex igr:gap-4">
                            {CHANNELS.map((c) => (
                                <label key={c} className="igr:flex igr:items-center igr:gap-2">
                                    <input type="checkbox" checked={form.consent_channels.includes(c)} disabled={disabled}
                                        onChange={(e) => set('consent_channels', e.target.checked ? [...form.consent_channels, c] : form.consent_channels.filter((x) => x !== c))} />
                                    {t(`agenda.channel.${c}`)}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend className="igr:font-medium">{t('agenda.page.reminders')}</legend>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.page.remindersHelp')}</p>
                        <div className="igr:mt-1 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                            {[0, 1].map((i) => (
                                <span key={i} className="igr:flex igr:items-center igr:gap-1">
                                    <Input type="number" min={1} max={72} className="igr:w-20" value={form.reminder_hours[i] ?? ''} disabled={disabled} aria-label={t('agenda.page.reminderN', { n: i + 1 })}
                                        onChange={(e) => {
                                            const next = [...form.reminder_hours];
                                            next[i] = Number(e.target.value);
                                            set('reminder_hours', next.filter((h) => h > 0));
                                        }} />
                                    {t('agenda.page.hoursBefore')}
                                </span>
                            ))}
                        </div>
                        <label className="igr:mt-2 igr:flex igr:items-center igr:gap-2">
                            <input type="checkbox" checked={form.auto_no_show} disabled={disabled} onChange={(e) => set('auto_no_show', e.target.checked)} />
                            {t('agenda.page.autoNoShow')}
                        </label>
                    </fieldset>
                    <fieldset>
                        <legend className="igr:font-medium">{t('agenda.page.waTemplates')}</legend>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.page.waTemplatesHelp')}</p>
                        <div className="igr:mt-1 igr:grid igr:gap-2 igr:sm:grid-cols-2">
                            {TEMPLATES.map((k) => (
                                <div key={k}>
                                    <Label htmlFor={`tpl-${k}`} className="igr:text-xs">{t(`agenda.page.tpl.${k}`)}</Label>
                                    <Input id={`tpl-${k}`} className="igr:mt-1" value={form.whatsapp_templates[k] ?? ''} placeholder="tpl_…" disabled={disabled}
                                        onChange={(e) => set('whatsapp_templates', { ...form.whatsapp_templates, [k]: e.target.value.trim() === '' ? null : e.target.value.trim() })} />
                                </div>
                            ))}
                        </div>
                    </fieldset>
                    {data.can_manage ? (
                        <div className="igr:flex igr:justify-end">
                            <Button onClick={save} loading={saveSettings.isPending}>
                                {t('common.save')}
                            </Button>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('agenda.page.share')}</CardTitle>
                    <CardDescription>{t('agenda.page.shareHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3 igr:text-[13px]">
                    <div className="igr:flex igr:gap-2">
                        <Input readOnly value={data.page_url} aria-label={t('agenda.page.link')} onFocus={(e) => e.target.select()} />
                        <Button variant="outline" size="icon" aria-label={t('common.copy')} onClick={() => copy(data.page_url)}>
                            <Copy />
                        </Button>
                        <Button variant="outline" size="icon" asChild>
                            <a href={data.page_url} target="_blank" rel="noreferrer" aria-label={t('agenda.openPage')}>
                                <ExternalLink />
                            </a>
                        </Button>
                    </div>
                    <div className="igr:flex igr:items-center igr:gap-3">
                        <img src={data.page_qr} alt={t('agenda.page.qr')} className="igr:size-32 igr:rounded-md igr:border igr:bg-white igr:p-1" />
                        <div className="igr:space-y-1">
                            <p className="igr:text-muted-foreground">{t('agenda.page.qrHelp')}</p>
                            <Button variant="link" size="sm" asChild>
                                <a href={data.page_qr} download="reservas-qr.svg">
                                    {t('agenda.page.downloadQr')}
                                </a>
                            </Button>
                        </div>
                    </div>
                    <div>
                        <p className="igr:font-medium">{t('agenda.page.onSite')}</p>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.page.onSiteHelp')}</p>
                        <div className="igr:mt-1 igr:flex igr:gap-2">
                            <code className="igr:flex-1 igr:truncate igr:rounded igr:bg-muted igr:px-2 igr:py-1.5">{shortcode}</code>
                            <Button variant="outline" size="icon" aria-label={t('common.copy')} onClick={() => copy(shortcode)}>
                                <Copy />
                            </Button>
                        </div>
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.page.domainHelp')}</p>
                </CardContent>
            </Card>
        </div>
    );
}
