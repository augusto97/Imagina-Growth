import { Bell, CalendarClock, CheckCheck, MessagesSquare, PlugZap, UserRoundPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type NoticeChannel, type NoticeGroup, type NoticeItem, type NoticePreferencesData, useMarkNoticesRead, useNoticePreferences, useNotices, useSaveNoticePreferences } from './api';
import { useNoticeCenter } from './model';

const GROUPS: NoticeGroup[] = ['appointments', 'leads', 'conversations', 'connections'];

function kindIcon(kind: string) {
    if (kind.startsWith('appointment.')) return CalendarClock;
    if (kind.startsWith('lead.')) return UserRoundPlus;
    if (kind.startsWith('conversation.')) return MessagesSquare;
    if (kind.startsWith('connection.')) return PlugZap;
    return Bell;
}

/** El centro de avisos (lote 0.4.0): los avisos de la persona y «Mis avisos». Se baja perezoso con sus textos. */
export function NoticeCenter() {
    const { t } = useTranslation();
    const open = useNoticeCenter((s) => s.open);
    const setOpen = useNoticeCenter((s) => s.setOpen);
    const [tab, setTab] = useState<'inbox' | 'preferences'>('inbox');

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetContent side="right" className="igr:sm:max-w-md" aria-describedby="igr-notices-desc">
                <SheetHeader>
                    <SheetTitle className="igr:flex igr:items-center igr:gap-2">
                        <Bell className="igr:size-4 igr:text-primary" aria-hidden />
                        {t('notices.title')}
                    </SheetTitle>
                    <SheetDescription id="igr-notices-desc">{t('notices.description')}</SheetDescription>
                </SheetHeader>
                <SheetBody className="igr:overflow-y-auto">
                    <Tabs value={tab} onValueChange={(v) => setTab(v === 'preferences' ? 'preferences' : 'inbox')}>
                        <TabsList>
                            <TabsTrigger value="inbox">{t('notices.tabs.inbox')}</TabsTrigger>
                            <TabsTrigger value="preferences">{t('notices.tabs.preferences')}</TabsTrigger>
                        </TabsList>
                        <TabsContent value="inbox">{open && tab === 'inbox' ? <NoticeInbox onNavigate={() => setOpen(false)} /> : null}</TabsContent>
                        <TabsContent value="preferences">{tab === 'preferences' ? <NoticePreferencesForm /> : null}</TabsContent>
                    </Tabs>
                </SheetBody>
            </SheetContent>
        </Sheet>
    );
}

function NoticeInbox({ onNavigate }: { onNavigate: () => void }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const list = useNotices(workspace.id, true);
    const read = useMarkNoticesRead(workspace.id);
    const items = list.data?.data ?? [];
    const unread = list.data?.unread ?? 0;

    const openItem = (item: NoticeItem) => {
        if (!item.read) read.mutate([item.id]);
        if (item.url) {
            onNavigate();
            void navigate(item.url);
        }
    };

    if (list.isPending) {
        return (
            <div className="igr:space-y-2" aria-busy>
                {[0, 1, 2].map((i) => (
                    <Skeleton key={i} className="igr:h-16" />
                ))}
            </div>
        );
    }
    if (list.isError) {
        return <Callout tone="danger" title={t('notices.error')} />;
    }
    if (items.length === 0) {
        return <EmptyState icon={Bell} title={t('notices.empty.title')} description={t('notices.empty.body')} size="compact" />;
    }

    return (
        <div className="igr:space-y-3">
            {unread > 0 ? (
                <div className="igr:flex igr:justify-end">
                    <Button size="sm" variant="ghost" onClick={() => read.mutate(null)} disabled={read.isPending}>
                        <CheckCheck />
                        {t('notices.markAll')}
                    </Button>
                </div>
            ) : null}
            <ul className="igr:divide-y igr:rounded-lg igr:border">
                {items.map((item) => {
                    const Icon = kindIcon(item.kind);
                    return (
                        <li key={item.id}>
                            <button
                                type="button"
                                onClick={() => openItem(item)}
                                className={cn('igr:flex igr:w-full igr:items-start igr:gap-3 igr:px-3 igr:py-3 igr:text-left igr:hover:bg-accent', !item.read && 'igr:bg-brand-soft/40')}
                            >
                                <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                <span className="igr:min-w-0 igr:flex-1">
                                    <span className={cn('igr:block igr:text-support', !item.read && 'igr:font-semibold')}>{item.title}</span>
                                    {item.body ? <span className="igr:mt-0.5 igr:line-clamp-2 igr:block igr:text-caption igr:text-muted-foreground">{item.body}</span> : null}
                                    {item.created_at ? <span className="igr:mt-0.5 igr:block igr:text-caption igr:text-muted-foreground">{formatRelative(item.created_at, i18n.language)}</span> : null}
                                </span>
                                {!item.read ? (
                                    <span className="igr:mt-1.5 igr:size-2 igr:shrink-0 igr:rounded-full igr:bg-primary" role="img" aria-label={t('notices.unread')} />
                                ) : null}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/** «Mis avisos»: qué avisos le llegan a esta persona y por dónde. */
export function NoticePreferencesForm() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const prefs = useNoticePreferences(workspace.id);
    if (prefs.isPending) {
        return <Skeleton className="igr:h-64" />;
    }
    if (prefs.isError) {
        return <Callout tone="danger" title={t('notices.error')} />;
    }
    // Al guardar llega lo guardado: el formulario vuelve a empezar desde ahí.
    return <PreferencesFields key={JSON.stringify([prefs.data.settings, prefs.data.phone])} data={prefs.data} />;
}

function PreferencesFields({ data }: { data: NoticePreferencesData }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveNoticePreferences(workspace.id);
    const [draft, setDraft] = useState<NoticePreferencesData['settings']>(data.settings);
    const [phone, setPhone] = useState(data.phone ?? '');
    const set = (group: NoticeGroup, patch: Partial<Record<NoticeChannel, boolean>> & { scope?: 'all' | 'mine' }) => setDraft({ ...draft, [group]: { ...draft[group], ...patch } });
    const wantsWhatsapp = draft.appointments.whatsapp === true;
    const phoneError = isApiError(save.error) ? save.error.fieldErrors.phone : undefined;

    return (
        <form
            className="igr:space-y-5"
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate({ settings: draft, phone: phone.trim() === '' ? null : phone.trim() }, { onSuccess: () => toast.success(t('notices.prefs.saved')) });
            }}
        >
            <p className="igr:text-support igr:text-muted-foreground">{t('notices.prefs.intro')}</p>
            {GROUPS.map((group) => {
                const channels = data.channels[group];
                const scoped = data.can_choose_scope && (group === 'appointments' || group === 'leads');
                if (group === 'connections' && !data.manager) return null;
                return (
                    <fieldset key={group} className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                        <legend className="igr:px-1 igr:text-support igr:font-semibold">{t(`notices.prefs.groups.${group}.title`)}</legend>
                        <p className="igr:text-caption igr:text-muted-foreground">{t(`notices.prefs.groups.${group}.help`)}</p>
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                            {channels.map((channel) => (
                                <Switch
                                    key={channel}
                                    size="sm"
                                    checked={draft[group][channel] === true}
                                    onCheckedChange={(checked) => set(group, { [channel]: checked })}
                                    label={t(`notices.prefs.channels.${channel}`)}
                                />
                            ))}
                        </div>
                        {scoped ? (
                            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3 igr:pt-1">
                                <span className="igr:text-caption igr:text-muted-foreground">{t('notices.prefs.scope.label')}</span>
                                {(['all', 'mine'] as const).map((scope) => (
                                    <label key={scope} className="igr:flex igr:items-center igr:gap-1.5 igr:text-support">
                                        <input type="radio" name={`scope-${group}`} className="igr:size-4" checked={draft[group].scope === scope} onChange={() => set(group, { scope })} />
                                        {t(`notices.prefs.scope.${scope}`)}
                                    </label>
                                ))}
                            </div>
                        ) : null}
                    </fieldset>
                );
            })}
            {wantsWhatsapp ? (
                <div className="igr:space-y-1.5">
                    <Label htmlFor="igr-notice-phone">{t('notices.prefs.phone.label')}</Label>
                    <Input
                        id="igr-notice-phone"
                        type="tel"
                        inputMode="tel"
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder={t('notices.prefs.phone.placeholder')}
                        aria-invalid={phoneError !== undefined}
                    />
                    <p className="igr:text-caption igr:text-muted-foreground">
                        {t('notices.prefs.phone.help')}
                        {data.phone_hint ? ` ${t('notices.prefs.phone.fromSeller', { phone: data.phone_hint })}` : ''}
                    </p>
                    {phoneError ? <p className="igr:text-caption igr:text-destructive">{phoneError}</p> : null}
                </div>
            ) : null}
            <p className="igr:text-caption igr:text-muted-foreground">{t('notices.prefs.pushHelp')}</p>
            <Button type="submit" disabled={save.isPending}>
                {t('notices.prefs.save')}
            </Button>
        </form>
    );
}
