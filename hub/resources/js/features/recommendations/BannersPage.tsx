import { Megaphone, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import {
    type Banner,
    type BannerAudience,
    type BannerInput,
    type BannersResponse,
    type BannerStyle,
    renderBannerText,
    useBanners,
    useDeleteBanner,
    useSaveBanner,
    usesPrivateVariables,
} from './api';

const AUDIENCES: BannerAudience[] = ['points', 'pending_cart', 'at_risk', 'segment'];
const STYLES: BannerStyle[] = ['brand', 'info', 'success', 'warning'];

const STYLE_CLASS: Record<BannerStyle, string> = {
    brand: 'igr:bg-primary igr:text-primary-foreground',
    info: 'igr:bg-sky-50 igr:text-sky-900 igr:dark:bg-sky-950 igr:dark:text-sky-100',
    success: 'igr:bg-emerald-50 igr:text-emerald-900 igr:dark:bg-emerald-950 igr:dark:text-emerald-100',
    warning: 'igr:bg-amber-50 igr:text-amber-900 igr:dark:bg-amber-950 igr:dark:text-amber-100',
};

/** Banners personalizados por segmento (M16). Se cargan por JS y solo muestran datos personales con sesión. */
export function BannersPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const banners = useBanners(workspace.id);
    const [editing, setEditing] = useState<Banner | 'new' | null>(null);
    const remove = useDeleteBanner(workspace.id);

    return (
        <>
            <PageHeader
                title={t('onsiteCommerce.banners.title')}
                description={t('onsiteCommerce.banners.description')}
                actions={
                    canManage ? (
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            {t('onsiteCommerce.banners.new')}
                        </Button>
                    ) : null
                }
            />
            {banners.isPending ? (
                <Skeleton className="igr:h-40" />
            ) : banners.isError ? (
                <ErrorState error={banners.error} onRetry={() => void banners.refetch()} />
            ) : banners.data.data.length === 0 ? (
                <EmptyState
                    icon={Megaphone}
                    title={t('onsiteCommerce.banners.empty.title')}
                    description={t('onsiteCommerce.banners.empty.description')}
                    action={canManage ? <Button onClick={() => setEditing('new')}>{t('onsiteCommerce.banners.new')}</Button> : undefined}
                />
            ) : (
                <div className="igr:grid igr:gap-3">
                    {banners.data.data.map((b) => (
                        <Card key={b.id}>
                            <CardContent className="igr:flex igr:flex-col igr:gap-3 igr:py-4 igr:md:flex-row igr:md:items-center">
                                <div className="igr:min-w-0 igr:flex-1 igr:space-y-2">
                                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                                        <span className="igr:text-sm igr:font-medium">{b.name}</span>
                                        <Badge variant={b.live ? 'success' : 'outline'}>{b.live ? t('onsiteCommerce.banners.live') : t('onsiteCommerce.banners.paused')}</Badge>
                                        <Badge variant="secondary">{t(`onsiteCommerce.banners.placement.${b.placement}`)}</Badge>
                                        <span className="igr:text-xs igr:text-muted-foreground">
                                            {b.audience === 'segment' && b.segment
                                                ? t('onsiteCommerce.banners.audienceSegment', { name: b.segment.name ?? '' })
                                                : t(`onsiteCommerce.banners.audience.${b.audience}`)}
                                        </span>
                                    </div>
                                    <BannerPreview banner={b} />
                                </div>
                                {canManage ? (
                                    <div className="igr:flex igr:gap-1">
                                        <Button variant="ghost" size="icon" aria-label={t('onsiteCommerce.banners.edit')} onClick={() => setEditing(b)}>
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('onsiteCommerce.banners.delete')}
                                            onClick={() => {
                                                if (window.confirm(t('onsiteCommerce.banners.deleteConfirm', { name: b.name }))) {
                                                    remove.mutate(b.id, { onSuccess: () => toast.success(t('onsiteCommerce.banners.deleted')) });
                                                }
                                            }}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
            {editing !== null && banners.data ? <BannerDialog banner={editing === 'new' ? null : editing} options={banners.data} onClose={() => setEditing(null)} /> : null}
        </>
    );
}

const SAMPLE = { nombre: 'Ana', puntos: '1.250', carrito_items: '2' };
/** Sin sesión el hub no llena el nombre ni los puntos. */
const PUBLIC_SAMPLE = { carrito_items: '2' };

/** Los textos de ayuda muestran las variables tal cual ({{nombre}}), no las interpolan. */
const VARIABLE_NAMES = { nombre: '{{nombre}}', puntos: '{{puntos}}', carrito_items: '{{carrito_items}}' };

/** Cómo se ve con sesión y sin sesión. */
function BannerPreview({ banner }: { banner: Pick<Banner, 'text' | 'text_public' | 'cta' | 'style'> }) {
    const { t } = useTranslation();
    const rows = [
        { label: t('onsiteCommerce.banners.withSession'), text: banner.text, vars: SAMPLE },
        { label: t('onsiteCommerce.banners.withoutSession'), text: banner.text_public ?? '', vars: PUBLIC_SAMPLE },
    ];
    return (
        <div className="igr:grid igr:gap-1.5">
            {rows.map((row) => (
                <div key={row.label} className="igr:flex igr:items-center igr:gap-2">
                    <span className="igr:w-24 igr:shrink-0 igr:text-[11px] igr:text-muted-foreground">{row.label}</span>
                    {row.text.trim() === '' ? (
                        <span className="igr:text-xs igr:text-muted-foreground igr:italic">{t('onsiteCommerce.banners.hidden')}</span>
                    ) : (
                        <div
                            className={cn(
                                'igr:flex igr:min-w-0 igr:flex-1 igr:items-center igr:justify-between igr:gap-3 igr:rounded-md igr:px-3 igr:py-1.5 igr:text-xs',
                                STYLE_CLASS[banner.style],
                            )}
                        >
                            <span className="igr:truncate">{renderBannerText(row.text, row.vars)}</span>
                            {banner.cta ? <span className="igr:shrink-0 igr:font-semibold igr:underline">{banner.cta}</span> : null}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

function BannerDialog({ banner, options, onClose }: { banner: Banner | null; options: BannersResponse; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveBanner(workspace.id);
    const [form, setForm] = useState<Required<Pick<BannerInput, 'name' | 'placement' | 'audience' | 'text' | 'style' | 'priority' | 'status'>> & BannerInput>({
        name: banner?.name ?? '',
        placement: banner?.placement ?? 'top',
        audience: banner?.audience ?? 'points',
        segment_id: banner?.segment?.id ?? null,
        min_points: banner?.min_points ?? null,
        text: banner?.text ?? '',
        text_public: banner?.text_public ?? '',
        cta: banner?.cta ?? '',
        url: banner?.url ?? '',
        style: banner?.style ?? 'brand',
        priority: banner?.priority ?? 100,
        status: banner?.status ?? 'active',
        site_id: banner?.site_id ?? null,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));
    const publicLeak = usesPrivateVariables(form.text_public);

    const submit = () => {
        const input: BannerInput = {
            ...form,
            segment_id: form.audience === 'segment' ? form.segment_id : null,
            min_points: form.audience === 'points' ? form.min_points : null,
            text_public: form.text_public || null,
            cta: form.cta || null,
            url: form.url || null,
        };
        save.mutate(
            { id: banner?.id ?? null, input },
            {
                onSuccess: () => {
                    toast.success(t('onsiteCommerce.banners.saved'));
                    onClose();
                },
                onError: (e) => {
                    if (isApiError(e, 'validation')) {
                        setErrors(e.fieldErrors);
                    } else {
                        toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
                    }
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{banner ? t('onsiteCommerce.banners.edit') : t('onsiteCommerce.banners.new')}</DialogTitle>
                    <DialogDescription>{t('onsiteCommerce.banners.formHelp')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:grid igr:gap-3 igr:md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="igr:space-y-1.5 igr:md:col-span-2">
                        <Label htmlFor="bn-name">{t('onsiteCommerce.banners.field.name')}</Label>
                        <Input id="bn-name" value={form.name} onChange={(e) => set('name', e.target.value)} aria-invalid={errors.name ? true : undefined} />
                        <FieldError message={errors.name} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-audience">{t('onsiteCommerce.banners.field.audience')}</Label>
                        <Select id="bn-audience" value={form.audience} onChange={(e) => set('audience', e.target.value as BannerAudience)}>
                            {AUDIENCES.map((a) => (
                                <option key={a} value={a}>
                                    {t(`onsiteCommerce.banners.audience.${a}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    {form.audience === 'segment' ? (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="bn-segment">{t('onsiteCommerce.banners.field.segment')}</Label>
                            <Select
                                id="bn-segment"
                                value={form.segment_id ?? ''}
                                onChange={(e) => set('segment_id', e.target.value || null)}
                                aria-invalid={errors.segment_id ? true : undefined}
                            >
                                <option value="">{t('onsiteCommerce.banners.field.chooseSegment')}</option>
                                {options.segments.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                            <FieldError message={errors.segment_id} />
                        </div>
                    ) : form.audience === 'points' ? (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="bn-points">{t('onsiteCommerce.banners.field.minPoints')}</Label>
                            <Input
                                id="bn-points"
                                type="number"
                                min={1}
                                value={form.min_points ?? ''}
                                onChange={(e) => set('min_points', e.target.value === '' ? null : Number(e.target.value))}
                            />
                        </div>
                    ) : (
                        <p className="igr:self-end igr:pb-1 igr:text-xs igr:text-muted-foreground">{t(`onsiteCommerce.banners.audienceHelp.${form.audience}`)}</p>
                    )}
                    <div className="igr:space-y-1.5 igr:md:col-span-2">
                        <Label htmlFor="bn-text">{t('onsiteCommerce.banners.field.text')}</Label>
                        <Textarea id="bn-text" rows={2} value={form.text} onChange={(e) => set('text', e.target.value)} aria-invalid={errors.text ? true : undefined} />
                        <p className="igr:text-[11px] igr:text-muted-foreground">{t('onsiteCommerce.banners.field.textHelp', VARIABLE_NAMES)}</p>
                        <FieldError message={errors.text} />
                    </div>
                    <div className="igr:space-y-1.5 igr:md:col-span-2">
                        <Label htmlFor="bn-public">{t('onsiteCommerce.banners.field.textPublic')}</Label>
                        <Textarea
                            id="bn-public"
                            rows={2}
                            value={form.text_public ?? ''}
                            onChange={(e) => set('text_public', e.target.value)}
                            aria-invalid={publicLeak || errors.text_public ? true : undefined}
                        />
                        <p className="igr:text-[11px] igr:text-muted-foreground">{t('onsiteCommerce.banners.field.textPublicHelp')}</p>
                        <FieldError message={publicLeak ? t('onsiteCommerce.banners.field.publicLeak', VARIABLE_NAMES) : errors.text_public} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-cta">{t('onsiteCommerce.banners.field.cta')}</Label>
                        <Input id="bn-cta" value={form.cta ?? ''} onChange={(e) => set('cta', e.target.value)} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-url">{t('onsiteCommerce.banners.field.url')}</Label>
                        <Input
                            id="bn-url"
                            type="url"
                            placeholder="https://"
                            value={form.url ?? ''}
                            onChange={(e) => set('url', e.target.value)}
                            aria-invalid={errors.url ? true : undefined}
                        />
                        <FieldError message={errors.url} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-placement">{t('onsiteCommerce.banners.field.placement')}</Label>
                        <Select id="bn-placement" value={form.placement} onChange={(e) => set('placement', e.target.value as Banner['placement'])}>
                            <option value="top">{t('onsiteCommerce.banners.placement.top')}</option>
                            <option value="account">{t('onsiteCommerce.banners.placement.account')}</option>
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-style">{t('onsiteCommerce.banners.field.style')}</Label>
                        <Select id="bn-style" value={form.style} onChange={(e) => set('style', e.target.value as BannerStyle)}>
                            {STYLES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`onsiteCommerce.banners.style.${s}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-priority">{t('onsiteCommerce.banners.field.priority')}</Label>
                        <Input id="bn-priority" type="number" min={0} max={1000} value={form.priority} onChange={(e) => set('priority', Number(e.target.value))} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="bn-status">{t('onsiteCommerce.banners.field.status')}</Label>
                        <Select id="bn-status" value={form.status} onChange={(e) => set('status', e.target.value as Banner['status'])}>
                            <option value="active">{t('onsiteCommerce.banners.live')}</option>
                            <option value="paused">{t('onsiteCommerce.banners.paused')}</option>
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5 igr:md:col-span-2">
                        <p className="igr:text-xs igr:font-medium igr:text-muted-foreground">{t('onsiteCommerce.banners.preview')}</p>
                        <BannerPreview banner={{ text: form.text, text_public: form.text_public ?? null, cta: form.cta ?? null, style: form.style }} />
                    </div>
                    <DialogFooter className="igr:md:col-span-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={save.isPending} disabled={publicLeak || form.name.trim() === '' || form.text.trim() === ''}>
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
