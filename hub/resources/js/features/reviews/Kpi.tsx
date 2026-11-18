import { CircleHelp } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { Tooltip } from '@/components/ui/tooltip';

/** Cifra principal con su contexto (stat tile, skill dataviz): número en tinta, nunca en color de serie. */
export function Kpi({ label, value, hint, help }: { label: string; value: ReactNode; hint?: ReactNode; help?: string }) {
    return (
        <Card className="igr:flex igr:min-w-0 igr:flex-col igr:p-4">
            <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:text-muted-foreground">
                <span className="igr:truncate">{label}</span>
                {help ? (
                    <Tooltip content={help}>
                        <CircleHelp className="igr:size-3.5 igr:shrink-0 igr:cursor-help" aria-label={help} />
                    </Tooltip>
                ) : null}
            </div>
            <div className="igr:mt-1.5 igr:truncate igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{value}</div>
            <div className="igr:mt-1 igr:min-h-4 igr:truncate igr:text-xs igr:text-muted-foreground">{hint}</div>
        </Card>
    );
}
