import { CircleCheck, CircleX, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/lib/format';

/* Lo que responde el plugin (`site.diagnostics`, connector/src/Diagnostics.php) en palabras del dueño (U5). */

type Tone = 'ok' | 'warn' | 'error';

interface Diagnostics {
    versions?: { plugin?: string; wp?: string; wc?: string | null; php?: string };
    outbox?: { pending?: number; dead?: number; oldest_pending_at?: string | null };
    action_scheduler?: { available?: boolean; lag_minutes?: number | null; wp_cron?: boolean };
    recent_errors?: unknown[];
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);

/** Cada fila del resumen: tono, título y explicación. Pura para probarla. */
export function diagnosticsRows(raw: unknown): { key: string; tone: Tone; params: Record<string, string | number> }[] {
    if (!isRecord(raw)) {
        return [];
    }
    const d = raw as Diagnostics;
    const rows: { key: string; tone: Tone; params: Record<string, string | number> }[] = [];
    const v = d.versions ?? {};
    rows.push({
        key: 'versions',
        tone: 'ok',
        params: { plugin: v.plugin ?? '—', wp: v.wp ?? '—', wc: v.wc ?? '—', php: v.php ?? '—' },
    });
    const as = d.action_scheduler ?? {};
    const lag = typeof as.lag_minutes === 'number' ? as.lag_minutes : null;
    if (as.available === false) {
        rows.push({ key: 'schedulerMissing', tone: 'error', params: {} });
    } else if (as.wp_cron === false && (lag === null || lag >= 10)) {
        rows.push({ key: 'cronDisabled', tone: 'error', params: {} });
    } else if (lag !== null && lag >= 10) {
        rows.push({ key: 'schedulerLate', tone: 'warn', params: { minutes: lag } });
    } else {
        rows.push({ key: 'schedulerOk', tone: 'ok', params: {} });
    }
    const pending = d.outbox?.pending ?? 0;
    const dead = d.outbox?.dead ?? 0;
    rows.push(pending > 0 ? { key: 'outboxPending', tone: pending > 500 ? 'warn' : 'ok', params: { count: pending } } : { key: 'outboxEmpty', tone: 'ok', params: {} });
    if (dead > 0) {
        rows.push({ key: 'outboxDead', tone: 'warn', params: { count: dead } });
    }
    const errors = Array.isArray(d.recent_errors) ? d.recent_errors.length : 0;
    rows.push(errors > 0 ? { key: 'errors', tone: 'warn', params: { count: errors } } : { key: 'noErrors', tone: 'ok', params: {} });
    return rows;
}

const ICON: Record<Tone, ReactNode> = {
    ok: <CircleCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />,
    warn: <TriangleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden />,
    error: <CircleX className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" aria-hidden />,
};

export function DiagnosticsSummary({ data, locale }: { data: unknown; locale: string }) {
    const { t } = useTranslation();
    const rows = diagnosticsRows(data);
    return (
        <div className="igr:space-y-3">
            <ul className="igr:space-y-2.5">
                {rows.map((row) => {
                    const params = Object.fromEntries(Object.entries(row.params).map(([k, v]) => [k, typeof v === 'number' ? formatNumber(v, locale) : v]));
                    const count = typeof row.params.count === 'number' ? row.params.count : undefined;
                    return (
                        <li key={row.key} className="igr:flex igr:items-start igr:gap-2.5">
                            {ICON[row.tone]}
                            <div className="igr:min-w-0">
                                <p className="igr:text-[13px] igr:font-medium">{t(`sites.diagnostics.${row.key}.title`, { ...params, count })}</p>
                                <p className="igr:text-xs igr:text-muted-foreground">{t(`sites.diagnostics.${row.key}.help`, { ...params, count })}</p>
                            </div>
                        </li>
                    );
                })}
            </ul>
            <details className="igr:rounded-lg igr:border igr:px-3 igr:py-2">
                <summary className="igr:cursor-pointer igr:text-xs igr:text-muted-foreground">{t('sites.diagnostics.raw')}</summary>
                <pre className="igr:mt-2 igr:max-h-[40vh] igr:overflow-auto igr:rounded igr:bg-muted igr:p-3 igr:font-mono igr:text-xs igr:leading-relaxed">
                    {JSON.stringify(data, null, 2)}
                </pre>
            </details>
        </div>
    );
}
