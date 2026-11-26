import { AlarmClock, ArrowRight, Check, ChevronDown, ExternalLink, EyeOff, Pencil, RotateCcw, X, Zap } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { makeFormatters } from '@/features/analytics/format';
import { toast } from '@/stores/toast';
import { type ImpactTerm, type Insight, SNOOZE_DAYS, useRunInsightAction, useUpdateAssumption, useUpdateInsight } from './api';

export function useInsightFormatters() {
    const { workspace } = useWorkspace();
    return useMemo(() => makeFormatters(workspace.locale, workspace.currency), [workspace.locale, workspace.currency]);
}

/** Un factor de la fórmula con su unidad (las tasas en porcentaje). */
export function useTermFormat() {
    const fmt = useInsightFormatters();
    return (term: Pick<ImpactTerm, 'unit' | 'value'>) => {
        switch (term.unit) {
            case 'money':
                return fmt.money(term.value);
            case 'rate':
                return fmt.percent(term.value, Number.isInteger(Math.round(term.value * 10000) / 100) ? 0 : 1);
            case 'count':
                return fmt.decimal(term.value, 1);
            default:
                return fmt.decimal(term.value, 2);
        }
    };
}

const ONE_CLICK = ['activate_flow', 'create_campaign', 'calendar_campaign'];

export function isOneClick(insight: Pick<Insight, 'action' | 'status'>): boolean {
    return ONE_CLICK.includes(insight.action?.kind ?? 'link') && (insight.status === 'new' || insight.status === 'seen' || insight.status === 'snoozed');
}

/**
 * El botón de la acción, con lo que hace («Crear la campaña de Black Friday», «Activa la automatización de carrito…»): con 1 clic
 * activa el flujo de la biblioteca, crea la campaña con el grupo elegido o la del calendario comercial (detector #24) y
 * lleva allí; las demás enlazan a la pantalla indicada.
 */
export function InsightActionButton({ insight, size = 'sm', className }: { insight: Insight; size?: 'sm' | 'md'; className?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const run = useRunInsightAction(workspace.id);
    const navigate = useNavigate();
    const action = insight.action;
    const base = `/w/${workspace.id}`;
    if (!action) return null;

    if (isOneClick(insight) && canManage) {
        return (
            <Button
                size={size}
                className={className}
                title={action.label}
                loading={run.isPending}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    run.mutate(insight.id, {
                        onSuccess: (res) => {
                            toast.success(t(`insights.actionResult.${res.result}`, { defaultValue: t('insights.actionResult.link') }));
                            void navigate(`${base}/${res.to}`);
                        },
                        onError: (err) => toast.error(isApiError(err) ? err.message : t('errors.generic')),
                    });
                }}
            >
                <Zap />
                <span className="igr:truncate">{action.label || t('insights.doNow')}</span>
            </Button>
        );
    }
    if (action.to) {
        return (
            <Button asChild size={size} variant="outline" className={className}>
                <Link to={`${base}/${action.to}`} onClick={(e) => e.stopPropagation()} title={action.label}>
                    <span className="igr:truncate">{action.label || t('insights.go')}</span>
                    <ArrowRight />
                </Link>
            </Button>
        );
    }
    if (action.url) {
        return (
            <Button asChild size={size} variant="outline" className={className}>
                <a href={action.url} target="_blank" rel="noreferrer">
                    {t('insights.open')}
                    <ExternalLink />
                </a>
            </Button>
        );
    }
    return null;
}

/**
 * Posponer (vuelve sola en 1, 3, 7 o 30 días) y descartar (no vuelve en 30 días salvo que empeore), juntos y con
 * nombre claro. Solo con permiso de marketing y en las abiertas o pospuestas.
 */
export function InsightTriage({ insight, compact = false }: { insight: Insight; compact?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const update = useUpdateInsight(workspace.id);
    if (!canManage || !(insight.status === 'new' || insight.status === 'seen' || insight.status === 'snoozed')) {
        return null;
    }
    const done = (status: 'dismissed' | 'snoozed', days?: number) =>
        update.mutate(
            { id: insight.id, status, days },
            {
                onSuccess: () => toast.success(status === 'snoozed' ? t('insights.toast.snoozed', { count: days ?? 1 }) : t('insights.toast.dismissed')),
                onError: (err) => toast.error(isApiError(err) ? err.message : t('errors.generic')),
            },
        );
    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="sm" variant="ghost" loading={update.isPending && update.variables.status === 'snoozed'} aria-label={compact ? t('insights.snooze') : undefined}>
                        <AlarmClock />
                        {compact ? null : t('insights.snooze')}
                        <ChevronDown className="igr:size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{t('insights.snoozeFor')}</DropdownMenuLabel>
                    {SNOOZE_DAYS.map((days) => (
                        <DropdownMenuItem key={days} onSelect={() => done('snoozed', days)}>
                            {t('insights.snoozeDays', { count: days })}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <Button size="sm" variant="ghost" loading={update.isPending && update.variables.status === 'dismissed'} onClick={() => done('dismissed')} aria-label={compact ? t('insights.dismiss') : undefined}>
                <EyeOff />
                {compact ? null : t('insights.dismiss')}
            </Button>
        </>
    );
}

/**
 * La fórmula del impacto, factor por factor (inteligencia.md §5.2). Los supuestos se
 * editan aquí mismo: al guardar se recalculan todos los hallazgos abiertos que lo usan.
 */
export function ImpactFormula({ insight }: { insight: Insight }) {
    const { t } = useTranslation();
    const fmt = useInsightFormatters();
    const format = useTermFormat();
    const canManage = useCan('manageMarketing');
    const formula = insight.formula;
    if (!formula) return null;

    return (
        <div className="igr:space-y-2">
            <ul className="igr:space-y-1.5 igr:text-[13px]" aria-label={t('insights.formula.title')}>
                {formula.terms.map((term, i) => (
                    <li key={i} className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                        {i > 0 ? (
                            <span className="igr:text-muted-foreground" aria-hidden>
                                ×
                            </span>
                        ) : null}
                        {term.assumption && canManage ? (
                            <AssumptionTerm term={term} />
                        ) : (
                            <span className="igr:font-medium igr:tabular-nums">{format(term)}</span>
                        )}
                        <span className="igr:text-muted-foreground">{term.label}</span>
                        {term.assumption ? <span className="igr:rounded igr:bg-muted igr:px-1 igr:py-px igr:text-[10px] igr:text-muted-foreground">{t('insights.formula.assumption')}</span> : null}
                    </li>
                ))}
            </ul>
            <p className="igr:border-t igr:pt-2 igr:text-[13px]">
                = <span className="igr:font-semibold igr:tabular-nums">{fmt.money(formula.total)}</span>
            </p>
        </div>
    );
}

function AssumptionTerm({ term }: { term: ImpactTerm }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const update = useUpdateAssumption(workspace.id);
    const format = useTermFormat();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(String(Math.round(term.value * 1000) / 10));
    const key = term.assumption ?? '';

    const save = (value: number | null) =>
        update.mutate(
            { key, value },
            {
                onSuccess: (res) => {
                    toast.success(t('insights.formula.saved', { count: res.updated }));
                    setEditing(false);
                },
                onError: (err) => toast.error(isApiError(err) ? err.message : t('errors.generic')),
            },
        );

    if (!editing) {
        return (
            <button
                type="button"
                onClick={() => setEditing(true)}
                className="igr:inline-flex igr:items-center igr:gap-1 igr:rounded igr:border igr:border-dashed igr:px-1.5 igr:py-0.5 igr:font-medium igr:tabular-nums igr:hover:bg-muted/60 igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:outline-none"
                aria-label={t('insights.formula.edit', { name: t(`insights.assumption.${key}`, { defaultValue: term.label }) })}
            >
                {format(term)}
                <Pencil className="igr:size-3 igr:text-muted-foreground" aria-hidden />
            </button>
        );
    }
    return (
        <form
            className="igr:inline-flex igr:items-center igr:gap-1"
            onSubmit={(e) => {
                e.preventDefault();
                const pct = Number(draft.replace(',', '.'));
                if (Number.isFinite(pct)) save(pct / 100);
            }}
        >
            <Input
                autoFocus
                inputMode="decimal"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                className="igr:h-7 igr:w-20 igr:text-right igr:tabular-nums"
                aria-label={t(`insights.assumption.${key}`, { defaultValue: term.label })}
            />
            <span className="igr:text-muted-foreground">%</span>
            <Button type="submit" size="icon" variant="ghost" className="igr:size-7" loading={update.isPending} aria-label={t('insights.formula.save')}>
                <Check />
            </Button>
            <Button type="button" size="icon" variant="ghost" className="igr:size-7" onClick={() => save(null)} aria-label={t('insights.formula.reset')}>
                <RotateCcw />
            </Button>
            <Button type="button" size="icon" variant="ghost" className="igr:size-7" onClick={() => setEditing(false)} aria-label={t('insights.formula.cancel')}>
                <X />
            </Button>
        </form>
    );
}
