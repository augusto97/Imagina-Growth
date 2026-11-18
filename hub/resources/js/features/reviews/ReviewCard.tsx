import { BadgeCheck, Check, CircleAlert, ExternalLink, MessageSquareReply, Play, Sparkles, Store, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { ReviewSummaryCard } from '@/features/intelligence/ReviewSummaryCard';
import { useModeration } from './api';
import { REJECT_REASONS, type RejectReason, type Review } from './types';

/** Estrellas de 1 a 5 (con el número para lectores de pantalla). */
export function Stars({ rating, className }: { rating: number; className?: string }) {
    const { t } = useTranslation();
    return (
        <span className={cn('igr:inline-flex igr:text-[15px] igr:leading-none igr:tracking-tight', className)} role="img" aria-label={t('reviews.stars', { count: rating })}>
            {[1, 2, 3, 4, 5].map((i) => (
                <span key={i} aria-hidden className={i <= rating ? 'igr:text-amber-500' : 'igr:text-muted-foreground/30'}>
                    ★
                </span>
            ))}
        </span>
    );
}

/** Tarjeta de una reseña con sus acciones de moderación. */
export function ReviewCard({ review, selected, onSelect }: { review: Review; selected?: boolean; onSelect?: (checked: boolean) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canModerate = useCan('manageMarketing');
    const { approve } = useModeration(workspace.id);
    const [dialog, setDialog] = useState<'reject' | 'reply' | null>(null);
    const [aiSummary, setAiSummary] = useState(false);

    const onApprove = () =>
        approve.mutate(review.id, {
            onSuccess: () => toast.success(t('reviews.card.approved')),
            onError: (e) => toast.error(errorMessage(e, t)),
        });

    return (
        <Card className={cn('igr:p-4', selected && 'igr:ring-2 igr:ring-primary/40')}>
            <div className="igr:flex igr:gap-3">
                {onSelect ? (
                    <input
                        type="checkbox"
                        className="igr:mt-1 igr:size-4 igr:shrink-0"
                        checked={selected ?? false}
                        onChange={(e) => onSelect(e.target.checked)}
                        aria-label={t('reviews.card.select', { author: review.author ?? t('reviews.card.anonymous') })}
                    />
                ) : null}
                <div className="igr:min-w-0 igr:flex-1">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1">
                        <Stars rating={review.rating} />
                        {review.title ? <span className="igr:font-medium">{review.title}</span> : null}
                        <StatusBadge review={review} />
                    </div>
                    <div className="igr:mt-1 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1 igr:text-xs igr:text-muted-foreground">
                        <span className="igr:inline-flex igr:items-center igr:gap-1">
                            {review.product ? (
                                <>
                                    {review.product.image ? <img src={review.product.image} alt="" className="igr:size-4 igr:rounded-sm igr:object-cover" /> : null}
                                    {review.product.name ?? t('reviews.card.product', { id: review.product.id })}
                                    <button
                                        type="button"
                                        onClick={() => setAiSummary((v) => !v)}
                                        aria-expanded={aiSummary}
                                        className="igr:ml-1 igr:inline-flex igr:items-center igr:gap-0.5 igr:rounded igr:px-1 igr:text-xs igr:text-primary igr:hover:underline"
                                    >
                                        <Sparkles className="igr:size-3" aria-hidden />
                                        {t('intelligence.reviews.open')}
                                    </button>
                                </>
                            ) : (
                                <>
                                    <Store className="igr:size-3.5" aria-hidden />
                                    {t('reviews.card.storeReview')}
                                </>
                            )}
                        </span>
                        <span aria-hidden>·</span>
                        <span>{review.author ?? t('reviews.card.anonymous')}</span>
                        {review.verified ? (
                            <span className="igr:inline-flex igr:items-center igr:gap-0.5 igr:text-success">
                                <BadgeCheck className="igr:size-3.5" aria-hidden />
                                {t('reviews.card.verified')}
                            </span>
                        ) : null}
                        <span aria-hidden>·</span>
                        <span>{t(`reviews.source.${review.source}`)}</span>
                        {review.order ? (
                            <>
                                <span aria-hidden>·</span>
                                <span>{t('reviews.card.order', { number: review.order.number })}</span>
                            </>
                        ) : null}
                        <span aria-hidden>·</span>
                        <time dateTime={review.created_at ?? undefined}>{formatDateTime(review.created_at, workspace.locale, workspace.timezone)}</time>
                    </div>

                    {review.flags.length > 0 ? (
                        <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-1.5">
                            {review.flags.map((flag) => (
                                <Badge key={flag} variant="warning">
                                    <CircleAlert className="igr:size-3" aria-hidden />
                                    {t(`reviews.flags.${flag}`)}
                                    {(review.flag_details[flag] ?? []).length > 0 ? ` (${(review.flag_details[flag] ?? []).map((d) => t(`reviews.flagDetails.${d}`)).join(', ')})` : ''}
                                </Badge>
                            ))}
                        </div>
                    ) : null}

                    {review.body ? <p className="igr:mt-2 igr:whitespace-pre-line igr:text-[13px] igr:leading-relaxed">{review.body}</p> : <p className="igr:mt-2 igr:text-[13px] igr:italic igr:text-muted-foreground">{t('reviews.card.noText')}</p>}

                    {review.media.length > 0 ? (
                        <ul className="igr:mt-3 igr:flex igr:flex-wrap igr:gap-2" aria-label={t('reviews.card.media')}>
                            {review.media.map((m, i) => (
                                <li key={m.url}>
                                    <a href={m.url} target="_blank" rel="noreferrer" className="igr:relative igr:block igr:size-16 igr:overflow-hidden igr:rounded-md igr:border igr:bg-muted">
                                        {m.type === 'image' ? (
                                            <img src={m.thumb ?? m.url} alt={t('reviews.card.photo', { n: i + 1 })} className="igr:size-full igr:object-cover" loading="lazy" />
                                        ) : (
                                            <span className="igr:flex igr:size-full igr:items-center igr:justify-center igr:text-muted-foreground">
                                                <Play className="igr:size-5" aria-hidden />
                                                <span className="igr:sr-only">{t('reviews.card.video', { n: i + 1 })}</span>
                                            </span>
                                        )}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {review.reply ? (
                        <div className="igr:mt-3 igr:rounded-md igr:border-l-2 igr:border-primary/40 igr:bg-muted/50 igr:px-3 igr:py-2 igr:text-[13px]">
                            <p className="igr:mb-0.5 igr:text-xs igr:font-medium igr:text-muted-foreground">{t('reviews.card.yourReply')}</p>
                            <p className="igr:whitespace-pre-line">{review.reply}</p>
                        </div>
                    ) : null}

                    {review.sync_status === 'failed' ? (
                        <p className="igr:mt-2 igr:text-xs igr:text-destructive">{t('reviews.card.syncFailed', { error: review.sync_error ?? '' })}</p>
                    ) : null}

                    <div className="igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {canModerate && review.status !== 'published' ? (
                            <Button size="sm" onClick={onApprove} loading={approve.isPending}>
                                <Check aria-hidden />
                                {t('reviews.card.approve')}
                            </Button>
                        ) : null}
                        {canModerate && review.status !== 'rejected' ? (
                            <Button size="sm" variant="outline" onClick={() => setDialog('reject')}>
                                <X aria-hidden />
                                {t('reviews.card.reject')}
                            </Button>
                        ) : null}
                        {canModerate ? (
                            <Button size="sm" variant="ghost" onClick={() => setDialog('reply')}>
                                <MessageSquareReply aria-hidden />
                                {review.reply ? t('reviews.card.editReply') : t('reviews.card.reply')}
                            </Button>
                        ) : null}
                        {review.contact_id ? (
                            <Button size="sm" variant="link" asChild>
                                <Link to={`/w/${workspace.id}/contactos/${review.contact_id}`}>
                                    {t('reviews.card.profile')}
                                    <ExternalLink aria-hidden />
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
            {aiSummary && review.product ? (
                <div className="igr:mt-3">
                    <ReviewSummaryCard productId={review.product.id} />
                </div>
            ) : null}
            <RejectDialog review={review} open={dialog === 'reject'} onClose={() => setDialog(null)} />
            <ReplyDialog review={review} open={dialog === 'reply'} onClose={() => setDialog(null)} />
        </Card>
    );
}

function StatusBadge({ review }: { review: Review }) {
    const { t } = useTranslation();
    if (review.status === 'published') {
        return <Badge variant="success">{review.on_store ? t('reviews.status.publishedStore') : t('reviews.status.published')}</Badge>;
    }
    if (review.status === 'rejected') {
        return <Badge variant="outline">{review.reject_reason ? t('reviews.status.rejectedBecause', { reason: t(`reviews.reasons.${review.reject_reason}`) }) : t('reviews.status.rejected')}</Badge>;
    }
    return <Badge variant="secondary">{t('reviews.status.pending')}</Badge>;
}

function RejectDialog({ review, open, onClose }: { review: Review; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { reject } = useModeration(workspace.id);
    const [reason, setReason] = useState<RejectReason>(review.flags[0] ?? 'off_topic');
    const submit = () =>
        reject.mutate(
            { id: review.id, reason },
            {
                onSuccess: () => {
                    toast.success(t('reviews.card.rejected'));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('reviews.reject.title')}</DialogTitle>
                    <DialogDescription>{t('reviews.reject.description')}</DialogDescription>
                </DialogHeader>
                <Label htmlFor={`reason-${review.id}`}>{t('reviews.reject.reason')}</Label>
                <Select id={`reason-${review.id}`} className="igr:mt-1.5" value={reason} onChange={(e) => setReason(e.target.value as RejectReason)}>
                    {REJECT_REASONS.map((r) => (
                        <option key={r} value={r}>
                            {t(`reviews.reasons.${r}`)}
                        </option>
                    ))}
                </Select>
                {review.on_store ? <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">{t('reviews.reject.onStore')}</p> : null}
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="destructive" onClick={submit} loading={reject.isPending}>
                        {t('reviews.card.reject')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ReplyDialog({ review, open, onClose }: { review: Review; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { reply } = useModeration(workspace.id);
    const [text, setText] = useState(review.reply ?? '');
    const submit = () =>
        reply.mutate(
            { id: review.id, text: text.trim() === '' ? null : text.trim() },
            {
                onSuccess: () => {
                    toast.success(t('reviews.reply.saved'));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('reviews.reply.title', { author: review.author ?? t('reviews.card.anonymous') })}</DialogTitle>
                    <DialogDescription>{review.on_store ? t('reviews.reply.onStore') : t('reviews.reply.description')}</DialogDescription>
                </DialogHeader>
                <Label htmlFor={`reply-${review.id}`}>{t('reviews.reply.label')}</Label>
                <Textarea id={`reply-${review.id}`} className="igr:mt-1.5" rows={5} maxLength={3000} value={text} onChange={(e) => setText(e.target.value)} placeholder={t('reviews.reply.placeholder')} />
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={reply.isPending}>
                        {t('reviews.reply.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
