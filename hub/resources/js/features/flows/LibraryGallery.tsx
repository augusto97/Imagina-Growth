import {
    CalendarCheck,
    CircleAlert,
    Eye,
    Heart,
    Mail,
    MessageCircle,
    MessageSquareText,
    PencilRuler,
    Plus,
    Repeat,
    Rocket,
    ShoppingCart,
    Sparkles,
    Star,
    Target,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card } from '@/components/ui/card';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { useCreateFromLibrary, useFlowLibrary } from './api';
import { businessKind, servesAudience } from './business';
import { LibraryPreviewSheet } from './LibraryPreviewSheet';
import type { LibraryFlow, LibraryGoal } from './types';

export const GOAL_ICON: Record<LibraryGoal, { icon: LucideIcon; tone: IconTone }> = {
    recover_sales: { icon: ShoppingCart, tone: 'orange' },
    sell_again: { icon: Repeat, tone: 'indigo' },
    loyalty: { icon: Heart, tone: 'rose' },
    capture: { icon: UserPlus, tone: 'teal' },
    reputation: { icon: Star, tone: 'amber' },
    convert_leads: { icon: Target, tone: 'violet' },
    appointments: { icon: CalendarCheck, tone: 'sky' },
};

const CHANNEL_ICON = { whatsapp: MessageCircle, email: Mail, sms: MessageSquareText } as const;

/** El orden de la galería: las recomendadas para la vertical primero, luego las de su tipo de negocio y el número. */
export function sortLibrary(flows: LibraryFlow[], kind: 'store' | 'services' | 'both'): LibraryFlow[] {
    // 0: hecha para este negocio; 1: sirve a los dos; 2: es del otro tipo de negocio.
    const fit = (f: LibraryFlow) => (!servesAudience(kind, f.audience) ? 2 : kind !== 'both' && f.audience === kind ? 0 : 1);
    return [...flows].sort((a, b) => (a.rank ?? 99) - (b.rank ?? 99) || fit(a) - fit(b) || a.number - b.number);
}

/**
 * La biblioteca como galería (rediseño v2 §11.4): por objetivo y para el tipo de negocio (en servicios, primero los de
 * servicios), con «Recomendadas para ti» según la vertical, la vista previa del recorrido y los mensajes antes de crear
 * y la opción «desde cero». Las que son parte de una estrategia enlazan a ella.
 */
export function LibraryGallery({ onFromScratch }: { onFromScratch?: (() => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const library = useFlowLibrary(workspace.id);
    const kind = businessKind(workspace);
    const [goal, setGoal] = useState<LibraryGoal | 'all'>('all');
    const [scope, setScope] = useState<'mine' | 'all'>(kind === 'both' ? 'all' : 'mine');
    const [preview, setPreview] = useState<string | null>(null);

    const visible = useMemo(() => {
        const flows = library.data?.flows ?? [];
        return sortLibrary(
            flows.filter((f) => (scope === 'all' || servesAudience(kind, f.audience)) && (goal === 'all' || f.goal === goal)),
            kind,
        );
    }, [library.data, scope, goal, kind]);

    if (library.isError) {
        return <ErrorState error={library.error} onRetry={() => void library.refetch()} />;
    }
    if (library.isPending) {
        return (
            <div className="igr:space-y-4" aria-busy="true">
                <Skeleton className="igr:h-9 igr:w-full igr:max-w-2xl" />
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {Array.from({ length: 6 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-52 igr:rounded-xl" />
                    ))}
                </div>
            </div>
        );
    }
    // En un negocio de servicios, primero convertir contactos y las citas.
    const order: LibraryGoal[] = kind === 'services' ? ['convert_leads', 'appointments', ...library.data.meta.goals.filter((g) => g !== 'convert_leads' && g !== 'appointments')] : library.data.meta.goals;
    const goals = order.filter((g) => library.data.flows.some((f) => f.goal === g && (scope === 'all' || servesAudience(kind, f.audience))));
    const recommended = goal === 'all' ? visible.filter((f) => f.rank !== null).slice(0, 3) : [];
    const rest = visible.filter((f) => !recommended.includes(f));
    const selected = library.data.flows.find((f) => f.key === preview) ?? null;

    return (
        <div className="igr:space-y-5">
            <div className="igr:flex igr:flex-col igr:gap-3 igr:lg:flex-row igr:lg:items-end igr:lg:justify-between">
                <div className="igr:min-w-0">
                    <h2 className="igr:text-section igr:font-semibold">{t('flows.library.title')}</h2>
                    <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t('flows.library.description', { count: library.data.flows.length })}</p>
                </div>
                {kind !== 'both' ? (
                    <div role="group" aria-label={t('flows.library.scope.label')} className="igr:inline-flex igr:shrink-0 igr:self-start igr:rounded-lg igr:border igr:bg-muted/40 igr:p-0.5">
                        {(['mine', 'all'] as const).map((s) => (
                            <button
                                key={s}
                                type="button"
                                aria-pressed={scope === s}
                                onClick={() => setScope(s)}
                                className={cn(
                                    'igr:rounded-md igr:px-3 igr:py-1 igr:text-support igr:font-medium igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                    scope === s ? 'igr:bg-card igr:text-foreground igr:shadow-xs' : 'igr:text-muted-foreground igr:hover:text-foreground',
                                )}
                            >
                                {t(`flows.library.scope.${s}`, { context: s === 'mine' ? kind : undefined })}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            <div role="group" aria-label={t('flows.library.goalsLabel')} className="igr:-mx-1 igr:flex igr:max-w-full igr:gap-1.5 igr:overflow-x-auto igr:px-1 igr:pb-1 igr:scrollbar-none">
                {(['all', ...goals] as const).map((g) => {
                    const spec = g === 'all' ? null : GOAL_ICON[g];
                    const Icon = spec?.icon ?? Sparkles;
                    return (
                        <button
                            key={g}
                            type="button"
                            aria-pressed={goal === g}
                            onClick={() => setGoal(g)}
                            className={cn(
                                'igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1.5 igr:rounded-full igr:border igr:px-3 igr:py-1.5 igr:text-support igr:font-medium igr:whitespace-nowrap igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                goal === g ? 'igr:border-primary/40 igr:bg-brand-soft igr:text-brand-soft-foreground' : 'igr:bg-card igr:text-muted-foreground igr:hover:text-foreground',
                            )}
                        >
                            <Icon className="igr:size-3.5" aria-hidden />
                            {t(`flows.library.goals.${g}`)}
                        </button>
                    );
                })}
            </div>
            {!library.data.whatsappConnected ? (
                <Callout
                    tone="info"
                    title={t('flows.library.noWhatsappTitle')}
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/conexiones`}>{t('flows.library.connectWhatsapp')}</Link>
                        </Button>
                    }
                >
                    {t('flows.library.noWhatsapp')}
                </Callout>
            ) : null}
            {recommended.length > 0 ? (
                <section aria-labelledby="lib-recommended" className="igr:space-y-2">
                    <h3 id="lib-recommended" className="igr:flex igr:items-center igr:gap-1.5 igr:text-support igr:font-semibold">
                        <Sparkles className="igr:size-3.5 igr:text-primary" aria-hidden />
                        {t('flows.library.recommended')}
                    </h3>
                    <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                        {recommended.map((f) => (
                            <LibraryCard key={f.key} flow={f} highlight showAudience={scope === 'all'} onPreview={() => setPreview(f.key)} />
                        ))}
                    </div>
                </section>
            ) : null}
            <section aria-label={t('flows.library.allLabel')} className="igr:space-y-2">
                {recommended.length > 0 ? <h3 className="igr:text-support igr:font-semibold">{t(goal === 'all' ? 'flows.library.moreAll' : 'flows.library.more')}</h3> : null}
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {onFromScratch ? <FromScratchCard onClick={onFromScratch} /> : null}
                    {rest.map((f) => (
                        <LibraryCard key={f.key} flow={f} showAudience={scope === 'all'} onPreview={() => setPreview(f.key)} />
                    ))}
                </div>
            </section>
            <LibraryPreviewSheet flow={selected} onClose={() => setPreview(null)} />
        </div>
    );
}

function FromScratchCard({ onClick }: { onClick: () => void }) {
    const { t } = useTranslation();
    return (
        <button
            type="button"
            onClick={onClick}
            className="igr:flex igr:min-h-52 igr:flex-col igr:items-center igr:justify-center igr:gap-2 igr:rounded-xl igr:border igr:border-dashed igr:bg-card/60 igr:p-5 igr:text-center igr:transition-colors igr:hover:border-primary/50 igr:hover:bg-brand-soft/40 igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
        >
            <IconTile icon={PencilRuler} tone="slate" />
            <span className="igr:font-semibold igr:text-foreground">{t('flows.library.fromScratch.title')}</span>
            <span className="igr:max-w-60 igr:text-support igr:text-muted-foreground">{t('flows.library.fromScratch.description')}</span>
            <span className="igr:mt-1 igr:inline-flex igr:items-center igr:gap-1 igr:text-support igr:font-medium igr:text-primary">
                <Plus className="igr:size-3.5" aria-hidden />
                {t('flows.library.fromScratch.action')}
            </span>
        </button>
    );
}

/** Crea el flujo de la biblioteca (activo o en borrador para personalizar) y avisa en palabras qué pasó. */
export function useRunLibraryFlow() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const create = useCreateFromLibrary(workspace.id);
    const navigate = useNavigate();
    const run = (flow: Pick<LibraryFlow, 'key'>, activate: boolean, done?: () => void) =>
        create.mutate(
            { key: flow.key, activate },
            {
                onSuccess: (detail) => {
                    done?.();
                    if (activate && detail.activated) {
                        toast.success(t('flows.library.activated', { name: detail.name }));
                        return;
                    }
                    if (activate) {
                        // Sin el canal conectado, el motivo exacto («Conecta WhatsApp en Canales…»).
                        toast.info(t('flows.library.createdDraft', { name: detail.name }), detail.issues.channel?.[0]);
                    }
                    void navigate(`/w/${workspace.id}/flujos/${detail.id}`);
                },
                onError: (error) => toast.error(t('flows.builder.saveFailed'), isApiError(error) ? error.message : undefined),
            },
        );
    return { run, pending: create.isPending, variables: create.variables };
}

function LibraryCard({ flow: f, onPreview, highlight = false, showAudience = false }: { flow: LibraryFlow; onPreview: () => void; highlight?: boolean; showAudience?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const { run, pending, variables } = useRunLibraryFlow();
    const spec = GOAL_ICON[f.goal];
    const busy = (activate: boolean) => pending && variables?.key === f.key && variables.activate === activate;

    return (
        <Card className={cn('igr:flex igr:flex-col igr:p-4 igr:transition-shadow igr:hover:shadow-md', highlight && 'igr:border-primary/30 igr:ring-1 igr:ring-primary/10')}>
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={spec.icon} tone={spec.tone} />
                <div className="igr:min-w-0 igr:flex-1">
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t(`flows.library.goals.${f.goal}`)}</p>
                    <h4 className="igr:font-semibold igr:leading-snug igr:text-foreground">{f.name}</h4>
                </div>
                {f.created_flow_id ? <Badge variant="success">{t('flows.library.inUse')}</Badge> : f.module ? <Badge variant="outline">{t('flows.library.byModule')}</Badge> : null}
            </div>
            <p className="igr:mt-2 igr:line-clamp-3 igr:text-support igr:text-muted-foreground">{f.description}</p>
            <div className="igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-1 igr:text-caption igr:text-muted-foreground">
                {f.channels.length > 0 ? (
                    <span className="igr:inline-flex igr:items-center igr:gap-1">
                        {f.channels.map((c) => {
                            const Icon = CHANNEL_ICON[c];
                            return <Icon key={c} className="igr:size-3.5" aria-label={t(`flows.channels.${c}`)} role="img" />;
                        })}
                        <span>{t('flows.library.messagesCount', { count: f.messages })}</span>
                    </span>
                ) : null}
                {showAudience && f.audience !== 'both' ? <span>{t(`flows.library.audience.${f.audience}`)}</span> : null}
            </div>
            {f.missing_templates.length > 0 && !f.created_flow_id ? (
                <p className="igr:mt-2 igr:flex igr:items-start igr:gap-1.5 igr:text-caption igr:text-warning">
                    <CircleAlert className="igr:mt-px igr:size-3.5 igr:shrink-0" aria-hidden />
                    <span>{t('flows.library.missingShort', { count: f.missing_templates.length })}</span>
                </p>
            ) : null}
            <div className="igr:mt-auto igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:pt-4">
                {f.module ? (
                    <Button asChild size="sm" variant="outline">
                        <Link to={`/w/${workspace.id}/${f.module}`}>{t('flows.library.configure')}</Link>
                    </Button>
                ) : f.created_flow_id ? (
                    <Button asChild size="sm" variant="outline">
                        <Link to={`/w/${workspace.id}/flujos/${f.created_flow_id}`}>{t('flows.library.open')}</Link>
                    </Button>
                ) : canManage ? (
                    f.missing_templates.length > 0 ? (
                        <Button size="sm" loading={busy(false)} onClick={() => run(f, false)}>
                            {t('flows.library.customize')}
                        </Button>
                    ) : (
                        <Button size="sm" loading={busy(true)} onClick={() => run(f, true)}>
                            {t('flows.library.activate')}
                        </Button>
                    )
                ) : null}
                {!f.module ? (
                    <Button size="sm" variant="ghost" onClick={onPreview}>
                        <Eye />
                        {t('flows.library.preview')}
                    </Button>
                ) : null}
                {f.strategy ? (
                    <Link
                        to={`/w/${workspace.id}/estrategias/${encodeURIComponent(f.strategy)}`}
                        className="igr:ml-auto igr:inline-flex igr:items-center igr:gap-1 igr:text-caption igr:font-medium igr:text-primary igr:hover:underline"
                    >
                        <Rocket className="igr:size-3" aria-hidden />
                        {t('flows.library.strategy')}
                    </Link>
                ) : null}
            </div>
        </Card>
    );
}
