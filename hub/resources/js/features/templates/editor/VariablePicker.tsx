import { Braces } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useVariableCatalog } from '../api';

/** Selector de variables Liquid (contacto, tienda, carrito, cupón…) para insertar donde está el cursor. */
export function VariablePicker({ onPick, disabled, size = 'sm', label }: { onPick: (expr: string) => void; disabled?: boolean; size?: 'sm' | 'icon'; label?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const catalog = useVariableCatalog(workspace.id);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size={size === 'icon' ? 'icon' : 'sm'} disabled={disabled} aria-label={label ?? t('templates.variables.insert')} title={label ?? t('templates.variables.insert')}>
                    <Braces className="igr:size-3.5" />
                    {size !== 'icon' && <span>{t('templates.variables.insert')}</span>}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="igr:max-h-[360px] igr:w-80 igr:overflow-y-auto">
                {catalog.isPending ? (
                    <DropdownMenuLabel className="igr:text-muted-foreground">{t('common.loading')}</DropdownMenuLabel>
                ) : (
                    (catalog.data ?? []).map((group, i) => (
                        <div key={group.key}>
                            {i > 0 && <DropdownMenuSeparator />}
                            <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                            {group.items.map((item) => (
                                <DropdownMenuItem key={item.expr} onSelect={() => onPick(item.expr)} className="igr:flex-col igr:items-start igr:gap-0.5">
                                    <span className="igr:flex igr:w-full igr:items-center igr:justify-between igr:gap-2">
                                        <span>{item.label}</span>
                                        {item.phase ? <span className="igr:text-[11px] igr:text-muted-foreground">{t('templates.phase', { n: item.phase })}</span> : null}
                                    </span>
                                    <code className="igr:max-w-full igr:truncate igr:text-[11px] igr:text-muted-foreground">{item.expr.split('\n')[0]}</code>
                                </DropdownMenuItem>
                            ))}
                        </div>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
