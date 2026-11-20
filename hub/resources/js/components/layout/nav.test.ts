import { describe, expect, it } from 'vitest';
import type { Ability } from '@/api/types';
import esCO from '@/locales/es-CO.json';
import { activeGroupKey, activeItem, flattenNav, isGroupOpen, NAV, visibleNav, type NavContext } from './nav';

const ALL: Ability[] = [
    'viewWorkspace',
    'viewDashboards',
    'manageWorkspace',
    'manageBilling',
    'manageMembers',
    'viewSites',
    'manageSites',
    'viewAllContacts',
    'viewAssignedContacts',
    'manageMarketing',
    'viewAuditLog',
    'useInbox',
    'useLeads',
    'manageApi',
];

const keys = (ctx: NavContext) => flattenNav(visibleNav(ctx)).map((i) => i.key);
const base = '/w/ws_1';

describe('menú agrupado', () => {
    it('agrupa por tarea y cada entrada tiene su texto y un atajo único', () => {
        expect(NAV.map((g) => g.key)).toEqual(['home', 'customers', 'sell', 'retain', 'measure', 'settings', 'admin']);
        const nav = esCO.nav as Record<string, unknown> & { groups: Record<string, string> };
        for (const group of NAV) {
            expect(nav.groups[group.key]).toBeTypeOf('string');
            for (const item of group.items) {
                expect(nav[item.key], item.key).toBeTypeOf('string');
            }
        }
        const shortcuts = flattenNav().flatMap((i) => (i.shortcut ? [i.shortcut] : []));
        expect(new Set(shortcuts).size).toBe(shortcuts.length);
    });

    it('sin datos del plan ni del sitio muestra todo lo que el rol permite', () => {
        const all = keys({ abilities: ALL });
        expect(all).toContain('loyalty');
        expect(all).toContain('leads');
        expect(all).toContain('quotes');
        expect(all).not.toContain('agency');
        expect(all).not.toContain('platform');
    });

    it('oculta los módulos fuera del plan', () => {
        const visible = keys({ abilities: ALL, modules: ['onsite', 'reviews', 'ai'] });
        expect(visible).toContain('onsite');
        expect(visible).toContain('reviews');
        for (const hidden of ['inbox', 'loyalty', 'referrals', 'leads', 'agenda', 'quotes']) {
            expect(visible).not.toContain(hidden);
        }
        // Lo que no depende del plan sigue.
        expect(visible).toContain('flows');
        expect(visible).toContain('analytics');
    });

    it('una tienda sin servicios no ve leads, agenda ni cotizador', () => {
        const visible = keys({ abilities: ALL, capabilities: { store: true, services: false } });
        expect(visible).toContain('loyalty');
        expect(visible).not.toContain('leads');
        expect(visible).not.toContain('agenda');
        expect(visible).not.toContain('quotes');
    });

    it('un sitio sin WooCommerce no ve lo exclusivo de tiendas', () => {
        const visible = keys({ abilities: ALL, capabilities: { store: false, services: true } });
        expect(visible).not.toContain('loyalty');
        expect(visible).not.toContain('referrals');
        expect(visible).toContain('reviews');
        expect(visible).toContain('leads');
    });

    it('quita los grupos que quedan vacíos', () => {
        const agent: Ability[] = ['viewWorkspace', 'viewAssignedContacts', 'useInbox', 'useLeads'];
        const groups = visibleNav({ abilities: agent, modules: ['inbox'], capabilities: { store: true, services: false } });
        expect(groups.map((g) => g.key)).toEqual(['customers', 'sell', 'settings']);
    });

    it('marca la entrada más específica de la página actual', () => {
        const groups = visibleNav({ abilities: ALL });
        expect(activeItem(groups, base, base)?.key).toBe('home');
        expect(activeItem(groups, `${base}/analitica/embudo`, base)?.key).toBe('analytics');
        expect(activeItem(groups, `${base}/en-el-sitio/cotizador/qc_1`, base)?.key).toBe('quotes');
        expect(activeItem(groups, `${base}/en-el-sitio/w_1`, base)?.key).toBe('onsite');
        expect(activeItem(groups, `${base}/contactos-x`, base)).toBeNull();
        expect(activeGroupKey(groups, `${base}/bandeja/agente`, base)).toBe('sell');
        expect(activeGroupKey(groups, `${base}/sitios/st_1`, base)).toBe('settings');
    });

    it('recuerda los grupos plegados y abre el de la página actual', () => {
        expect(isGroupOpen('measure', [], null, false)).toBe(true);
        expect(isGroupOpen('measure', ['measure'], null, false)).toBe(false);
        // El grupo activo se abre aunque esté plegado…
        expect(isGroupOpen('measure', ['measure'], 'measure', false)).toBe(true);
        // …salvo que la persona lo pliegue en esa página.
        expect(isGroupOpen('measure', ['measure'], 'measure', true)).toBe(false);
        expect(isGroupOpen('home', ['home'], null, false)).toBe(true);
    });
});
