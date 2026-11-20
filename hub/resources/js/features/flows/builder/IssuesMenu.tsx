import { ChevronDown, CircleAlert, CircleCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { metaOf } from '../catalog';
import type { GraphNode } from '../types';

/**
 * Validación en la barra: «Listo para publicar» o cuántos pendientes hay. Con pendientes abre la
 * lista con el motivo de cada uno y lleva al paso.
 */
export function IssuesMenu({ issues, nodes, onFocus }: { issues: Record<string, string[]>; nodes: GraphNode[]; onFocus: (nodeId: string) => void }) {
    const { t } = useTranslation();
    const count = Object.values(issues).reduce((acc, list) => acc + list.length, 0);
    if (count === 0) {
        return (
            // En una barra angosta (tableta) solo el ícono: el nombre del flujo necesita el espacio.
            <span className="igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1 igr:text-xs igr:text-success" title={t('flows.builder.noIssues')}>
                <CircleCheck className="igr:size-3.5" aria-hidden />
                <span className="igr:sr-only igr:@4xl:not-sr-only">{t('flows.builder.noIssues')}</span>
            </span>
        );
    }
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const entries = Object.entries(issues).filter(([, list]) => list.length > 0);
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="igr:inline-flex igr:h-7 igr:shrink-0 igr:items-center igr:gap-1 igr:rounded-md igr:px-2 igr:text-xs igr:font-medium igr:text-destructive igr:hover:bg-destructive/10 igr:data-[state=open]:bg-destructive/10"
                >
                    <CircleAlert className="igr:size-3.5" aria-hidden />
                    {t('flows.builder.issues', { count })}
                    <ChevronDown className="igr:size-3.5" aria-hidden />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="igr:max-h-[60vh] igr:w-80 igr:overflow-y-auto">
                <DropdownMenuLabel>{t('flows.canvas.issuesHelp')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {entries.map(([id, list]) => {
                    const node = byId.get(id);
                    const Icon = node ? metaOf(node.type).icon : CircleAlert;
                    const name = id === '' ? t('flows.canvas.wholeFlow') : node ? t(`flows.nodes.${node.type}`, { defaultValue: node.type }) : t('flows.canvas.deletedStep');
                    return (
                        <DropdownMenuItem key={id || 'graph'} disabled={id !== '' && !node} onSelect={() => onFocus(id === '' ? 'trigger' : id)} className="igr:items-start">
                            <Icon aria-hidden className="igr:mt-0.5" />
                            <span className="igr:min-w-0 igr:flex-1">
                                <span className="igr:block igr:font-medium">{name}</span>
                                {list.map((m) => (
                                    <span key={m} className="igr:block igr:text-xs igr:text-destructive">
                                        {m}
                                    </span>
                                ))}
                            </span>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
