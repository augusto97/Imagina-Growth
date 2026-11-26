import { BarChart3, CircleHelp, Download, Table2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { HelpTip } from '@/components/ui/help-tip';
import { cn } from '@/lib/cn';

/**
 * Bloque de un tablero: título, ayuda, exportación CSV y, si hay gráfico y
 * tabla, el cambio entre ambos (la tabla es la vista accesible del gráfico).
 *
 * `kicker` es el nombre del dato («Ventas por canal») y `title`, la conclusión («Google trae el 45 % de tus ventas»):
 * cada gráfica dice qué significa, no solo qué muestra. En el celular las acciones bajan debajo del título.
 */
export function Section({
    title,
    kicker,
    description,
    help,
    onExport,
    chart,
    table,
    children,
    className,
    flush = false,
    actions,
}: {
    title: ReactNode;
    /** El nombre del dato, arriba del título cuando el título es la conclusión. */
    kicker?: ReactNode;
    description?: ReactNode;
    help?: string;
    onExport?: () => void;
    chart?: ReactNode;
    table?: ReactNode;
    children?: ReactNode;
    className?: string;
    /** Sin relleno interior (tablas a todo el ancho de la tarjeta). */
    flush?: boolean;
    actions?: ReactNode;
}) {
    const { t } = useTranslation();
    const [view, setView] = useState<'chart' | 'table'>('chart');
    const both = chart !== undefined && table !== undefined;
    const body = both ? (view === 'chart' ? chart : table) : (children ?? chart ?? table);
    const flat = flush || (both && view === 'table') || (!both && table !== undefined && chart === undefined);

    return (
        <Card className={cn('igr:min-w-0', className)}>
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-x-3 igr:gap-y-2 igr:space-y-0">
                <div className="igr:min-w-0 igr:flex-1 igr:basis-64">
                    {kicker ? <p className="igr:mb-0.5 igr:text-caption igr:font-medium igr:text-muted-foreground">{kicker}</p> : null}
                    <CardTitle className="igr:flex igr:items-start igr:gap-1.5">
                        <span className="igr:min-w-0">{title}</span>
                        {help ? <HelpTip content={help} label={typeof help === 'string' ? help : undefined} className="igr:mt-1" /> : null}
                    </CardTitle>
                    {description ? <CardDescription className="igr:mt-0.5">{description}</CardDescription> : null}
                </div>
                <div className="igr:flex igr:shrink-0 igr:items-center igr:gap-1">
                    {actions}
                    {both ? (
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => setView((v) => (v === 'chart' ? 'table' : 'chart'))}
                            aria-label={view === 'chart' ? t('analytics.section.showTable') : t('analytics.section.showChart')}
                            title={view === 'chart' ? t('analytics.section.showTable') : t('analytics.section.showChart')}
                        >
                            {view === 'chart' ? <Table2 /> : <BarChart3 />}
                        </Button>
                    ) : null}
                    {onExport ? (
                        <Button variant="ghost" size="icon" onClick={onExport} aria-label={t('analytics.section.export')} title={t('analytics.section.export')}>
                            <Download />
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className={cn(flat && 'igr:px-0 igr:pb-0')}>{body}</CardContent>
        </Card>
    );
}

/** Aviso dentro de un bloque cuando una función llega en otra fase o falta un dato. */
export function Notice({ icon: Icon, children }: { icon: typeof CircleHelp; children: ReactNode }) {
    return (
        <div className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:border-dashed igr:px-3 igr:py-2.5 igr:text-[13px] igr:text-muted-foreground">
            <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0" />
            <div>{children}</div>
        </div>
    );
}
