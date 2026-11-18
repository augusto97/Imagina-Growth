import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useReviewSettings, useSaveReviewSettings } from './api';
import { CHANNELS, moveChannel, settingsPayload, settingsProblems, toggleChannel } from './logic';
import type { Channel, ReviewSettings, ReviewSettingsResponse } from './types';

/** Ajustes de reseñas: solicitud automática, moderación, Google y publicación en la tienda. */
export function SettingsTab() {
    const { workspace } = useWorkspace();
    const settings = useReviewSettings(workspace.id);
    if (settings.isError) return <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;
    if (settings.isPending) return <Skeleton className="igr:h-[36rem]" />;
    return <Editor key={JSON.stringify(settings.data.settings)} data={settings.data} />;
}

function Editor({ data }: { data: ReviewSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const save = useSaveReviewSettings(workspace.id);
    const [draft, setDraft] = useState<ReviewSettings>(data.settings);
    const [showErrors, setShowErrors] = useState(false);
    const { options } = data;
    const problems = settingsProblems(draft, options);
    const serverErrors = isApiError(save.error) ? save.error.fieldErrors : {};
    const r = draft.request;
    const setReq = (patch: Partial<ReviewSettings['request']>) => setDraft({ ...draft, request: { ...r, ...patch } });
    const err = (key: string) => (showErrors && problems.includes(key) ? t(`reviews.settings.problems.${key}`) : undefined);

    const submit = () => {
        if (problems.length > 0) {
            setShowErrors(true);
            return;
        }
        save.mutate(settingsPayload(draft), {
            onSuccess: () => {
                setShowErrors(false);
                toast.success(t('reviews.settings.saved'));
            },
            onError: (e) => toast.error(errorMessage(e, t)),
        });
    };

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
            <fieldset disabled={!canEdit} className="igr:min-w-0 igr:space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('reviews.settings.requestTitle')}</CardTitle>
                        <CardDescription>{t('reviews.settings.requestDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-4">
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                            <input type="checkbox" className="igr:size-4" checked={r.enabled} onChange={(e) => setReq({ enabled: e.target.checked })} />
                            {t('reviews.settings.enabled')}
                        </label>
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                            <div>
                                <Label htmlFor="rs-trigger">{t('reviews.settings.trigger')}</Label>
                                <Select id="rs-trigger" className="igr:mt-1.5" value={r.trigger} onChange={(e) => setReq({ trigger: e.target.value as 'completed' | 'paid' })}>
                                    <option value="completed">{t('reviews.settings.triggers.completed')}</option>
                                    <option value="paid">{t('reviews.settings.triggers.paid')}</option>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="rs-days">{t('reviews.settings.days')}</Label>
                                <Input id="rs-days" className="igr:mt-1.5" type="number" min={0} max={120} value={r.days} onChange={(e) => setReq({ days: Number(e.target.value) })} />
                            </div>
                            <div>
                                <Label htmlFor="rs-link">{t('reviews.settings.linkDays')}</Label>
                                <Input id="rs-link" className="igr:mt-1.5" type="number" min={7} max={180} value={r.link_days} onChange={(e) => setReq({ link_days: Number(e.target.value) })} />
                            </div>
                        </div>
                        <FieldError message={err('days')} />

                        <div>
                            <p className="igr:text-[13px] igr:font-medium">{t('reviews.settings.categoryDays')}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('reviews.settings.categoryDaysHelp')}</p>
                            <div className="igr:mt-2 igr:space-y-2">
                                {r.category_days.map((row, i) => (
                                    <div key={i} className="igr:flex igr:items-center igr:gap-2">
                                        <Select
                                            aria-label={t('reviews.settings.category', { n: i + 1 })}
                                            value={row.category_id}
                                            onChange={(e) => setReq({ category_days: r.category_days.map((c, j) => (j === i ? { ...c, category_id: e.target.value } : c)) })}
                                        >
                                            <option value="">{t('reviews.settings.chooseCategory')}</option>
                                            {options.categories.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </Select>
                                        <Input
                                            aria-label={t('reviews.settings.categoryDaysFor', { n: i + 1 })}
                                            className="igr:w-24"
                                            type="number"
                                            min={0}
                                            max={120}
                                            value={row.days}
                                            onChange={(e) => setReq({ category_days: r.category_days.map((c, j) => (j === i ? { ...c, days: Number(e.target.value) } : c)) })}
                                        />
                                        <span className="igr:text-xs igr:text-muted-foreground">{t('reviews.settings.daysUnit')}</span>
                                        <Button size="icon" variant="ghost" aria-label={t('reviews.settings.removeCategory', { n: i + 1 })} onClick={() => setReq({ category_days: r.category_days.filter((_, j) => j !== i) })}>
                                            <Trash2 aria-hidden />
                                        </Button>
                                    </div>
                                ))}
                                <Button size="sm" variant="outline" disabled={options.categories.length === 0} onClick={() => setReq({ category_days: [...r.category_days, { category_id: '', days: r.days }] })}>
                                    <Plus aria-hidden />
                                    {t('reviews.settings.addCategory')}
                                </Button>
                                <FieldError message={err('categories')} />
                            </div>
                        </div>

                        <fieldset>
                            <legend className="igr:text-[13px] igr:font-medium">{t('reviews.settings.channels')}</legend>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('reviews.settings.channelsHelp')}</p>
                            <ol className="igr:mt-2 igr:space-y-2">
                                {[...r.channels, ...CHANNELS.filter((c) => !r.channels.includes(c))].map((c) => {
                                    const active = r.channels.includes(c);
                                    const index = r.channels.indexOf(c);
                                    const key = `${c}_template_id` as const;
                                    return (
                                        <li key={c} className="igr:rounded-md igr:border igr:p-2.5">
                                            <div className="igr:flex igr:items-center igr:gap-2">
                                                <label className="igr:flex igr:flex-1 igr:items-center igr:gap-2 igr:text-[13px]">
                                                    <input type="checkbox" className="igr:size-4" checked={active} onChange={() => setReq({ channels: toggleChannel(r.channels, c) })} />
                                                    {active ? <span className="igr:text-xs igr:text-muted-foreground">{index + 1}.</span> : null}
                                                    {t(`reviews.channels.${c}`)}
                                                </label>
                                                {active ? (
                                                    <>
                                                        <Button size="icon" variant="ghost" disabled={index === 0} aria-label={t('reviews.settings.moveUp', { channel: t(`reviews.channels.${c}`) })} onClick={() => setReq({ channels: moveChannel(r.channels, index, -1) })}>
                                                            <ArrowUp aria-hidden />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            disabled={index === r.channels.length - 1}
                                                            aria-label={t('reviews.settings.moveDown', { channel: t(`reviews.channels.${c}`) })}
                                                            onClick={() => setReq({ channels: moveChannel(r.channels, index, 1) })}
                                                        >
                                                            <ArrowDown aria-hidden />
                                                        </Button>
                                                    </>
                                                ) : null}
                                            </div>
                                            {active ? (
                                                <Select
                                                    className="igr:mt-2"
                                                    aria-label={t('reviews.settings.templateFor', { channel: t(`reviews.channels.${c}`) })}
                                                    value={r[key] ?? ''}
                                                    onChange={(e) => setReq({ [key]: e.target.value === '' ? null : e.target.value } as Partial<ReviewSettings['request']>)}
                                                >
                                                    <option value="">{c === 'email' ? t('reviews.settings.libraryTemplate') : t('reviews.settings.chooseTemplate')}</option>
                                                    {options.templates[c as Channel].map((tpl) => (
                                                        <option key={tpl.id} value={tpl.id}>
                                                            {tpl.name}
                                                        </option>
                                                    ))}
                                                </Select>
                                            ) : null}
                                            {active && c === 'whatsapp' ? <FieldError message={err('whatsappTemplate') ?? serverErrors['request.whatsapp_template_id']} /> : null}
                                            {active && c === 'sms' ? <FieldError message={err('smsTemplate') ?? serverErrors['request.sms_template_id']} /> : null}
                                        </li>
                                    );
                                })}
                            </ol>
                            <FieldError message={err('noChannel')} />
                        </fieldset>

                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={r.skip_reviewed} onChange={(e) => setReq({ skip_reviewed: e.target.checked })} />
                            <span>
                                {t('reviews.settings.skipReviewed')}
                                <span className="igr:block igr:text-xs igr:text-muted-foreground">{t('reviews.settings.skipReviewedHelp')}</span>
                            </span>
                        </label>

                        <div className="igr:rounded-md igr:border igr:p-3">
                            <Label htmlFor="rs-kind">{t('reviews.settings.kind')}</Label>
                            <Select id="rs-kind" className="igr:mt-1.5" value={r.kind} disabled={!options.can_reclassify} onChange={(e) => setReq({ kind: e.target.value as 'marketing' | 'utility' })}>
                                <option value="marketing">{t('reviews.settings.kinds.marketing')}</option>
                                <option value="utility">{t('reviews.settings.kinds.utility')}</option>
                            </Select>
                            <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('reviews.settings.kindHelp')}</p>
                            {r.kind === 'utility' ? (
                                <div className="igr:mt-2">
                                    <Label htmlFor="rs-reason">{t('reviews.settings.reclassifyReason')}</Label>
                                    <Textarea id="rs-reason" className="igr:mt-1.5" rows={2} maxLength={500} value={r.reclassify_reason ?? ''} onChange={(e) => setReq({ reclassify_reason: e.target.value })} />
                                    <FieldError message={err('reclassifyReason') ?? serverErrors['request.reclassify_reason']} />
                                </div>
                            ) : null}
                            {!options.can_reclassify ? <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('reviews.settings.reclassifyOnlyAdmins')}</p> : null}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('reviews.settings.moderationTitle')}</CardTitle>
                        <CardDescription>{t('reviews.settings.moderationDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-3">
                        {(['manual', 'auto'] as const).map((mode) => (
                            <label key={mode} className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                                <input type="radio" name="rs-mode" className="igr:mt-0.5 igr:size-4" checked={draft.moderation.mode === mode} onChange={() => setDraft({ ...draft, moderation: { mode } })} />
                                <span>
                                    {t(`reviews.settings.modes.${mode}`)}
                                    <span className="igr:block igr:text-xs igr:text-muted-foreground">{t(`reviews.settings.modesHelp.${mode}`)}</span>
                                </span>
                            </label>
                        ))}
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={draft.publish_to_store} onChange={(e) => setDraft({ ...draft, publish_to_store: e.target.checked })} />
                            <span>
                                {t('reviews.settings.publishToStore')}
                                <span className="igr:block igr:text-xs igr:text-muted-foreground">{t('reviews.settings.publishToStoreHelp')}</span>
                            </span>
                        </label>
                        <div>
                            <Label htmlFor="rs-reply">{t('reviews.settings.replyAuthor')}</Label>
                            <Input id="rs-reply" className="igr:mt-1.5" maxLength={80} value={draft.reply_author ?? ''} placeholder={workspace.name} onChange={(e) => setDraft({ ...draft, reply_author: e.target.value })} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('reviews.settings.googleTitle')}</CardTitle>
                        <CardDescription>{t('reviews.settings.googleDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Label htmlFor="rs-google">{t('reviews.settings.googleUrl')}</Label>
                        <Input id="rs-google" className="igr:mt-1.5" type="url" inputMode="url" placeholder="https://g.page/r/…/review" value={draft.google_review_url ?? ''} onChange={(e) => setDraft({ ...draft, google_review_url: e.target.value })} />
                        <FieldError message={err('googleUrl') ?? serverErrors.google_review_url} />
                        <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">{t('reviews.settings.noGating')}</p>
                    </CardContent>
                </Card>
                {canEdit ? (
                    <Button onClick={submit} loading={save.isPending}>
                        {t('common.save')}
                    </Button>
                ) : (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('reviews.settings.readOnly')}</p>
                )}
            </fieldset>

            <div className="igr:space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('reviews.settings.rewardsTitle')}</CardTitle>
                        <CardDescription>{options.loyalty_active ? t('reviews.settings.rewardsActive', { review: options.rewards.review, media: options.rewards.media }) : t('reviews.settings.rewardsInactive')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button size="sm" variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/fidelizacion?tab=reglas`}>{t('reviews.settings.rewardsLink')}</Link>
                        </Button>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('reviews.settings.howTitle')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="igr:list-decimal igr:space-y-1.5 igr:pl-4 igr:text-[13px] igr:text-muted-foreground">
                            <li>{t('reviews.settings.how1')}</li>
                            <li>{t('reviews.settings.how2')}</li>
                            <li>{t('reviews.settings.how3')}</li>
                            <li>{options.photos_supported ? t('reviews.settings.how4') : t('reviews.settings.how4NoPhotos')}</li>
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
