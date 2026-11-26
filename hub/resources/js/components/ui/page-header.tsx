import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router';
import { activeChild, itemPath, sectionPath } from '@/components/layout/nav';
import { usePageContext } from '@/components/layout/pageContext';
import { cn } from '@/lib/cn';
import { IconTile, type IconTone } from './icon-tile';

export interface Crumb {
    label: ReactNode;
    to?: string;
}

/**
 * La miga automática: la sección (y la entrada, en un detalle) de la página actual. Vacía en Inicio, en las secciones de
 * una sola página y en la página de entrada de cada sección (ahí el título ya es la sección).
 */
export function useAutoCrumbs(): Crumb[] {
    const { t } = useTranslation();
    const ctx = usePageContext();
    const { pathname } = useLocation();
    if (!ctx || ctx.isEntry || ctx.item === null) {
        return [];
    }
    const item = ctx.item;
    const crumbs: Crumb[] = [{ label: t(`nav.sections.${ctx.section.key}`), to: sectionPath(ctx.base, ctx.section) }];
    // Una pantalla hermana de la entrada (Conexiones → Tu web): la entrada y, en un detalle, la pantalla hermana.
    const childKey = activeChild(item, pathname, ctx.base);
    const child = item.children?.find((c) => c.key === childKey);
    if (child && child.to !== item.to) {
        crumbs.push({ label: t(`nav.${item.key}`), to: itemPath(ctx.base, item) });
        if (pathname.replace(/\/+$/, '') !== `${ctx.base}/${child.to}`) {
            crumbs.push({ label: t(`nav.${child.key}`), to: `${ctx.base}/${child.to}` });
        }
        return crumbs;
    }
    if (ctx.isDetail) {
        crumbs.push({ label: t(`nav.${item.key}`), to: itemPath(ctx.base, item) });
    }
    return crumbs;
}

/** La miga automática sola, para las pantallas de detalle con un encabezado propio (perfil, sitio, editor). */
export function AutoBreadcrumbs({ className }: { className?: string }) {
    return <Breadcrumbs items={useAutoCrumbs()} className={cn('igr:mb-3', className)} />;
}

export function Breadcrumbs({ items, className }: { items: Crumb[]; className?: string }) {
    const { t } = useTranslation();
    if (items.length === 0) {
        return null;
    }
    return (
        <nav aria-label={t('ui.breadcrumb')} className={className}>
            <ol className="igr:flex igr:min-w-0 igr:flex-wrap igr:items-center igr:gap-1 igr:text-caption igr:font-medium igr:text-muted-foreground">
                {items.map((crumb, i) => (
                    <li key={i} className="igr:flex igr:min-w-0 igr:items-center igr:gap-1">
                        {i > 0 ? <ChevronRight className="igr:size-3 igr:shrink-0 igr:opacity-60" aria-hidden /> : null}
                        {crumb.to ? (
                            <Link to={crumb.to} className="igr:truncate igr:rounded-sm igr:transition-colors igr:hover:text-foreground">
                                {crumb.label}
                            </Link>
                        ) : (
                            <span className="igr:truncate">{crumb.label}</span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

/**
 * Encabezado de página v2 (rediseño v2 §7, «plantilla de página»): miga de la sección, título, una línea de para qué
 * sirve la pantalla y, a la derecha, la acción principal y las secundarias. Es el mismo en todas las pantallas.
 *
 * - `description`: una sola idea, en segunda persona y sin jerga («Quién te compra y quién está por volver»).
 * - `actions`: la principal (variante `default`) al final; las secundarias antes, en `outline` o `ghost`. En el celular
 *   bajan debajo del título y se acomodan en varias líneas sin desbordar.
 * - `breadcrumb`: automática según la URL (`useAutoCrumbs`); `false` la quita y una lista la reemplaza.
 * - `icon` y `tone`: mosaico opcional junto al título (páginas de entrada y estrategias).
 * - `meta`: insignias junto al título (estado, plan…).
 */
export function PageHeader({
    title,
    description,
    actions,
    breadcrumb,
    icon,
    tone = 'indigo',
    meta,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    breadcrumb?: Crumb[] | false;
    icon?: LucideIcon;
    tone?: IconTone;
    meta?: ReactNode;
    className?: string;
}) {
    const auto = useAutoCrumbs();
    const crumbs = breadcrumb === false ? [] : (breadcrumb ?? auto);
    return (
        <header className={cn('igr:mb-6 igr:flex igr:flex-col igr:gap-4 igr:sm:flex-row igr:sm:items-start igr:sm:justify-between', className)}>
            <div className="igr:min-w-0 igr:flex-1">
                <Breadcrumbs items={crumbs} className="igr:mb-1.5" />
                <div className="igr:flex igr:min-w-0 igr:items-center igr:gap-3">
                    {icon ? <IconTile icon={icon} tone={tone} size="lg" /> : null}
                    <div className="igr:min-w-0">
                        <div className="igr:flex igr:min-w-0 igr:flex-wrap igr:items-center igr:gap-x-2.5 igr:gap-y-1">
                            <h1 className="igr:text-[22px] igr:leading-7 igr:font-semibold igr:tracking-tight igr:break-words igr:text-foreground igr:sm:text-title">{title}</h1>
                            {meta}
                        </div>
                        {description ? <p className="igr:mt-1 igr:max-w-3xl igr:text-body igr:text-muted-foreground">{description}</p> : null}
                    </div>
                </div>
            </div>
            {actions ? <div className="igr:flex igr:max-w-full igr:flex-wrap igr:items-center igr:gap-2 igr:sm:shrink-0 igr:sm:justify-end igr:sm:pt-1">{actions}</div> : null}
        </header>
    );
}
