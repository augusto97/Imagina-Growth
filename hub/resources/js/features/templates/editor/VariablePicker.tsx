import { Braces } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { businessKind, type BusinessKind } from '@/features/flows/business';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useVariableCatalog, type VariableGroup } from '../api';

/** Grupos que solo tienen datos en una tienda o en un negocio de servicios (nunca «carrito» ni «pedido» sin tienda). */
const STORE_GROUPS = ['carrito', 'cupon', 'pedido', 'producto', 'recomendaciones'];
const SERVICE_GROUPS = ['cita', 'lead', 'cotizacion', 'evento'];

/** Los grupos que aplican al negocio, los de servicios primero en un negocio de servicios. */
export function groupsFor(groups: VariableGroup[], kind: BusinessKind): VariableGroup[] {
    const visible = groups.filter((g) => (kind === 'services' ? !STORE_GROUPS.includes(g.key) : kind === 'store' ? !SERVICE_GROUPS.includes(g.key) : true));
    if (kind !== 'services') return visible;
    const rank = (g: VariableGroup) => (g.key === 'contacto' ? 0 : SERVICE_GROUPS.includes(g.key) ? 1 : 2);
    return [...visible].sort((a, b) => rank(a) - rank(b));
}

/** «{{ enlaces.agendar | default: … }}» → «enlaces_agendar», para la ayuda de cada variable. */
export function exprKey(expr: string): string {
    const m = /\{\{\s*([a-z_]+)\.([a-z_]+)/.exec(expr);
    return m ? `${m[1]}_${m[2]}` : '';
}

/** Selector de variables Liquid (contacto, tu negocio, cita, carrito…) para insertar donde está el cursor. */
export function VariablePicker({ onPick, disabled, size = 'sm', label }: { onPick: (expr: string) => void; disabled?: boolean; size?: 'sm' | 'icon'; label?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const catalog = useVariableCatalog(workspace.id);
    const groups = groupsFor(catalog.data ?? [], businessKind(workspace));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size={size === 'icon' ? 'icon' : 'sm'} disabled={disabled} aria-label={label ?? t('templates.variables.insert')} title={label ?? t('templates.variables.insert')}>
                    <Braces className="igr:size-3.5" />
                    {size !== 'icon' && <span>{t('templates.variables.insert')}</span>}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="igr:max-h-[420px] igr:w-[340px] igr:max-w-[calc(100vw-1rem)] igr:overflow-y-auto">
                {catalog.isPending ? (
                    <DropdownMenuLabel className="igr:text-muted-foreground">{t('common.loading')}</DropdownMenuLabel>
                ) : (
                    groups.map((group, i) => (
                        <div key={group.key}>
                            {i > 0 && <DropdownMenuSeparator />}
                            <DropdownMenuLabel className="igr:pb-0.5">{t(`templates.variables.groups.${group.key}.label`, { defaultValue: group.label })}</DropdownMenuLabel>
                            {t(`templates.variables.groups.${group.key}.help`, { defaultValue: '' }) !== '' ? (
                                <p className="igr:px-2 igr:pb-1 igr:text-caption igr:text-muted-foreground">{t(`templates.variables.groups.${group.key}.help`)}</p>
                            ) : null}
                            {group.items.map((item) => {
                                const hint = t(`templates.variables.hints.${exprKey(item.expr)}`, { defaultValue: '' });
                                return (
                                    <DropdownMenuItem key={item.expr} onSelect={() => onPick(item.expr)} className="igr:flex-col igr:items-start igr:gap-0.5">
                                        <span className="igr:flex igr:w-full igr:items-baseline igr:justify-between igr:gap-2">
                                            <span>{item.label}</span>
                                            <span className="igr:max-w-[45%] igr:truncate igr:text-caption igr:text-muted-foreground">{t('templates.variables.example', { value: item.example })}</span>
                                        </span>
                                        {hint !== '' ? <span className="igr:text-caption igr:text-muted-foreground">{hint}</span> : null}
                                        <code className="igr:max-w-full igr:truncate igr:text-[11px] igr:text-muted-foreground">{item.expr.split('\n')[0]}</code>
                                    </DropdownMenuItem>
                                );
                            })}
                        </div>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
