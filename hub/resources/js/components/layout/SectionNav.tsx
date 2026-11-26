import { LayoutGrid, Lock } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router';
import { cn } from '@/lib/cn';
import { activeChild, itemLock, itemPath, sectionPath, type NavContext, type NavItem, type NavLocation, type NavLock, type NavSection } from './nav';

/** Texto corto del candado de una entrada (para el lector de pantalla y el tooltip nativo). */
export function lockLabelKey(lock: NavLock): string {
    return lock.kind === 'plan' ? 'ui.locked.short.plan' : lock.kind === 'site' ? 'ui.locked.short.site' : 'ui.locked.short.channel';
}

interface Entry {
    key: string;
    label: string;
    to: string;
    icon: NavItem['icon'];
    /** La persona está en esta entrada (o en una pantalla dentro de ella). */
    active: boolean;
    lock: NavLock | null;
    /** Pantallas hermanas de la entrada activa (Conexiones: WhatsApp y anuncios, tu web, canales). */
    children: { key: string; label: string; to: string; active: boolean }[];
}

function useEntries(section: NavSection, location: NavLocation, base: string, ctx: NavContext, withOverview: boolean): Entry[] {
    const { t } = useTranslation();
    const { pathname } = useLocation();
    const items = section.items.map((item): Entry => {
        const active = location.item?.key === item.key;
        const child = active ? activeChild(item, pathname, base) : null;
        return {
            key: item.key,
            label: t(`nav.${item.key}`),
            to: itemPath(base, item),
            icon: item.icon,
            active,
            lock: itemLock(item, ctx),
            children: active ? (item.children ?? []).map((c) => ({ key: c.key, label: t(`nav.${c.key}`), to: `${base}/${c.to}`, active: child === c.key })) : [],
        };
    });
    return withOverview
        ? [{ key: 'overview', label: t('ui.sectionNav.overview'), to: sectionPath(base, section), icon: LayoutGrid, active: location.isEntry, lock: null, children: [] }, ...items]
        : items;
}

function LockMark({ lock }: { lock: NavLock }) {
    const { t } = useTranslation();
    return (
        <>
            <Lock className="igr:size-3 igr:shrink-0 igr:opacity-70" aria-hidden />
            <span className="igr:sr-only">{` (${t(lockLabelKey(lock))})`}</span>
        </>
    );
}

/**
 * Subnavegación de la sección dentro de la barra lateral (rediseño v2 §3, a la izquierda del contenido en el escritorio,
 * como Polaris o Stripe): las entradas de la sección actual, debajo de ella y con sangría. Sin íconos para no competir
 * con las secciones; las que no pueden servir todavía llevan un candado. La entrada actual es `aria-current="page"`.
 */
export function SectionNavTree({ section, location, base, ctx }: { section: NavSection; location: NavLocation; base: string; ctx: NavContext }) {
    const { t } = useTranslation();
    const entries = useEntries(section, location, base, ctx, false);
    return (
        <ul
            aria-label={t('ui.sectionNav.label', { section: t(`nav.sections.${section.key}`) })}
            className="igr:mt-0.5 igr:mb-2 igr:ml-[19px] igr:space-y-0.5 igr:border-l igr:pl-2.5"
        >
            {entries.map((entry) => (
                <li key={entry.key}>
                    <Link
                        to={entry.to}
                        aria-current={entry.active && entry.children.length === 0 ? 'page' : undefined}
                        title={entry.lock ? t(lockLabelKey(entry.lock)) : undefined}
                        className={cn(
                            'igr:relative igr:flex igr:min-h-8 igr:items-center igr:gap-2 igr:rounded-md igr:px-2.5 igr:py-1.5 igr:text-[13px] igr:leading-tight igr:transition-colors',
                            entry.active
                                ? 'igr:bg-card igr:font-medium igr:text-foreground igr:shadow-xs igr:ring-1 igr:ring-border'
                                : 'igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                        )}
                    >
                        <span className="igr:min-w-0 igr:flex-1">{entry.label}</span>
                        {entry.lock ? <LockMark lock={entry.lock} /> : null}
                    </Link>
                    {entry.children.length > 0 ? (
                        <ul className="igr:mt-0.5 igr:mb-1 igr:ml-3 igr:space-y-0.5 igr:border-l igr:pl-2">
                            {entry.children.map((child) => (
                                <li key={child.key}>
                                    <Link
                                        to={child.to}
                                        aria-current={child.active ? 'page' : undefined}
                                        className={cn(
                                            'igr:block igr:rounded-md igr:px-2 igr:py-1.5 igr:text-[12.5px] igr:leading-tight igr:transition-colors',
                                            child.active ? 'igr:font-medium igr:text-primary' : 'igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                                        )}
                                    >
                                        {child.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/**
 * Subnavegación de la sección como control segmentado arriba del contenido (rediseño v2 §8): en el celular y la tableta,
 * y en el escritorio cuando la barra lateral está contraída. Se desplaza de lado sin barra visible y con la entrada
 * activa a la vista. Empieza por «Resumen» (la página de entrada de la sección). Una entrada activa con pantallas
 * hermanas se abre en ellas (Conexiones → WhatsApp y anuncios, tu web, canales).
 */
export function SectionNav({ section, location, base, ctx, className }: { section: NavSection; location: NavLocation; base: string; ctx: NavContext; className?: string }) {
    const { t } = useTranslation();
    const entries = useEntries(section, location, base, ctx, true);
    const scroller = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const list = scroller.current;
        const active = list?.querySelector<HTMLElement>('[aria-current="page"]');
        if (!list || !active || list.scrollWidth <= list.clientWidth) {
            return;
        }
        const left = active.getBoundingClientRect().left - list.getBoundingClientRect().left + list.scrollLeft;
        if (left < list.scrollLeft || left + active.offsetWidth > list.scrollLeft + list.clientWidth) {
            list.scrollLeft = Math.max(0, left - 16);
        }
    });

    const pill = (active: boolean) =>
        cn(
            'igr:flex igr:h-8 igr:items-center igr:gap-1.5 igr:rounded-lg igr:px-3 igr:text-[13px] igr:whitespace-nowrap igr:transition-colors',
            active ? 'igr:bg-card igr:font-medium igr:text-foreground igr:shadow-sm' : 'igr:text-muted-foreground igr:hover:text-foreground',
        );

    return (
        <nav aria-label={t('ui.sectionNav.label', { section: t(`nav.sections.${section.key}`) })} className={cn('igr:mb-5', className)}>
            <div ref={scroller} className="igr:scrollbar-none igr:relative igr:-mx-4 igr:overflow-x-auto igr:px-4 igr:md:mx-0 igr:md:px-0">
                <ul className="igr:inline-flex igr:min-w-max igr:items-center igr:gap-1 igr:rounded-xl igr:border igr:bg-muted/60 igr:p-1">
                    {entries.flatMap((entry) => {
                        const Icon = entry.icon;
                        if (entry.children.length > 0) {
                            return entry.children.map((child) => (
                                <li key={`${entry.key}-${child.key}`} className="igr:shrink-0">
                                    <Link to={child.to} aria-current={child.active ? 'page' : undefined} className={pill(child.active)}>
                                        <Icon className={cn('igr:size-3.5 igr:shrink-0', child.active && 'igr:text-primary')} aria-hidden />
                                        {child.label}
                                    </Link>
                                </li>
                            ));
                        }
                        return [
                            <li key={entry.key} className="igr:shrink-0">
                                <Link to={entry.to} aria-current={entry.active ? 'page' : undefined} className={pill(entry.active)}>
                                    <Icon className={cn('igr:size-3.5 igr:shrink-0', entry.active && 'igr:text-primary')} aria-hidden />
                                    {entry.label}
                                    {entry.lock ? <LockMark lock={entry.lock} /> : null}
                                </Link>
                            </li>,
                        ];
                    })}
                </ul>
            </div>
        </nav>
    );
}
