import { describe, expect, it } from 'vitest';
import type { Ability } from '@/api/types';
import esCO from '@/locales/es-CO.json';
import sections from '@/locales/es-CO/sections.json';
import i18n from '@/lib/i18n';
import { activeChild, flattenNav, itemLock, locate, SECTIONS, sectionLock, sectionName, visibleSections, type NavContext, type NavItem } from './nav';

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

const keys = (ctx: NavContext) => flattenNav(visibleSections(ctx)).map((i) => i.key);
const base = '/w/ws_1';
const item = (key: string): NavItem => {
    const found = SECTIONS.flatMap((s) => s.items).find((i) => i.key === key);
    if (!found) {
        throw new Error(key);
    }
    return found;
};

describe('secciones del menú (rediseño v2 §3)', () => {
    it('ocho secciones con nombre de resultado, cada entrada con su nombre, su «para qué sirve» y un atajo único', () => {
        expect(SECTIONS.filter((s) => !s.admin).map((s) => s.key)).toEqual(['home', 'strategies', 'customers', 'conversations', 'messages', 'onsite', 'results', 'settings']);
        const nav = esCO.nav as Record<string, unknown> & { sections: Record<string, string> };
        const purposes = sections.sections as unknown as Record<string, { purpose?: string }> & { items: Record<string, string> };
        for (const section of SECTIONS) {
            expect(nav.sections[section.key], section.key).toBeTypeOf('string');
            expect(purposes[section.key]?.purpose, section.key).toBeTypeOf('string');
            for (const entry of section.items) {
                expect(nav[entry.key], entry.key).toBeTypeOf('string');
                expect(purposes.items[entry.key], entry.key).toBeTypeOf('string');
                for (const child of entry.children ?? []) {
                    expect(nav[child.key], child.key).toBeTypeOf('string');
                }
            }
        }
        const shortcuts = flattenNav().flatMap((i) => (i.shortcut ? [i.shortcut] : []));
        expect(new Set(shortcuts).size).toBe(shortcuts.length);
    });

    it('las URL de siempre siguen y cada una cae en su sección', () => {
        const all = visibleSections({ abilities: [...ALL, 'viewAgency', 'platformAdmin'] }, true);
        const where = (path: string) => {
            const loc = locate(all, `${base}${path}`, base);
            return loc ? `${loc.section.key}/${loc.item?.key ?? '-'}${loc.isDetail ? '+' : ''}` : null;
        };
        expect(where('')).toBe('home/-');
        expect(where('/estrategias')).toBe('strategies/-');
        expect(where('/clientes')).toBe('customers/-');
        expect(where('/contactos')).toBe('customers/contacts');
        expect(where('/contactos/ct_1')).toBe('customers/contacts+');
        expect(where('/contactos/importar')).toBe('customers/contacts+');
        expect(where('/segmentos/nuevo')).toBe('customers/segments+');
        expect(where('/bandeja')).toBe('conversations/inbox');
        expect(where('/bandeja/cv_1')).toBe('conversations/inbox+');
        expect(where('/bandeja/agente')).toBe('conversations/agent');
        expect(where('/flujos/fl_1')).toBe('messages/flows+');
        expect(where('/canales/plantillas-whatsapp')).toBe('messages/templates');
        expect(where('/en-el-sitio/w_1')).toBe('onsite/onsite+');
        expect(where('/en-el-sitio/cotizador/qc_1')).toBe('onsite/quotes+');
        expect(where('/en-el-sitio/avisos')).toBe('onsite/watches');
        expect(where('/analitica/embudo')).toBe('results/analytics+');
        expect(where('/inteligencia/predicciones')).toBe('results/predictions');
        expect(where('/inteligencia/preguntale')).toBe('results/intelligence+');
        expect(where('/reportes')).toBe('results/reports');
        expect(where('/sitios/st_1')).toBe('settings/connections+');
        expect(where('/canales')).toBe('settings/connections');
        expect(where('/guia-de-estilo')).toBe('styleguide/-');
        expect(where('/contactos-x')).toBeNull();
        expect(where('/estrategias/carritos')).toBe('strategies/-');
    });

    it('sin datos del plan ni del sitio muestra todo lo que el rol permite, sin candados', () => {
        const ctx: NavContext = { abilities: ALL };
        const all = keys(ctx);
        expect(all).toContain('loyalty');
        expect(all).toContain('leads');
        expect(all).toContain('quotes');
        expect(all).not.toContain('agency');
        expect(all).not.toContain('platform');
        expect(all).not.toContain('styleguide');
        expect(SECTIONS.flatMap((s) => s.items).every((i) => itemLock(i, ctx) === null)).toBe(true);
    });

    it('lo que está fuera del plan no se oculta: lleva candado con el plan que lo incluye', () => {
        const ctx: NavContext = { abilities: ALL, modules: ['onsite', 'reviews', 'ai'], upgradePlans: { loyalty: 'Tienda · Crece' } };
        const visible = keys(ctx);
        for (const shown of ['inbox', 'loyalty', 'referrals', 'leads', 'agenda', 'quotes', 'onsite', 'flows']) {
            expect(visible).toContain(shown);
        }
        expect(itemLock(item('loyalty'), ctx)).toEqual({ kind: 'plan', module: 'loyalty', plan: 'Tienda · Crece' });
        expect(itemLock(item('inbox'), ctx)).toEqual({ kind: 'plan', module: 'inbox', plan: null });
        expect(itemLock(item('onsite'), ctx)).toBeNull();
        expect(itemLock(item('flows'), ctx)).toBeNull();
    });

    it('sin web conectada, lo que depende de ella enseña qué falta; una web que se desconectó conserva sus datos', () => {
        const none: NavContext = { abilities: ALL, connections: { site: 'none', channels: [] } };
        expect(itemLock(item('analytics'), none)).toEqual({ kind: 'site', state: 'none' });
        expect(itemLock(item('contacts'), none)).toBeNull();
        expect(itemLock(item('inbox'), none)).toEqual({ kind: 'channel' });
        expect(itemLock(item('analytics'), { ...none, connections: { site: 'pending', channels: [] } })).toEqual({ kind: 'site', state: 'pending' });
        expect(itemLock(item('analytics'), { ...none, connections: { site: 'disconnected', channels: [] } })).toBeNull();
        // El correo no conversa en la bandeja; WhatsApp sí.
        expect(itemLock(item('inbox'), { ...none, connections: { site: 'connected', channels: ['email'] } })).toEqual({ kind: 'channel' });
        expect(itemLock(item('inbox'), { ...none, connections: { site: 'connected', channels: ['whatsapp'] } })).toBeNull();
        // El plan se resuelve primero.
        expect(itemLock(item('loyalty'), { ...none, modules: [] })?.kind).toBe('plan');
    });

    it('la sección lleva la marca solo si todas sus entradas esperan lo mismo', () => {
        const ctx: NavContext = { abilities: ALL, connections: { site: 'none', channels: [] } };
        const list = visibleSections(ctx);
        const lockOf = (key: string) => {
            const section = list.find((s) => s.key === key);
            if (!section) {
                throw new Error(key);
            }
            return sectionLock(section, ctx);
        };
        expect(lockOf('onsite')).toEqual({ kind: 'site', state: 'none' });
        expect(lockOf('results')).toEqual({ kind: 'site', state: 'none' });
        expect(lockOf('conversations')).toEqual({ kind: 'channel' });
        expect(lockOf('customers')).toBeNull();
        expect(lockOf('home')).toBeNull();
    });

    it('una tienda sin servicios no ve leads, agenda ni cotizador; un sitio sin WooCommerce no ve lo exclusivo de tiendas', () => {
        const store = keys({ abilities: ALL, capabilities: { store: true, services: false } });
        expect(store).toContain('loyalty');
        expect(store).not.toContain('leads');
        expect(store).not.toContain('agenda');
        expect(store).not.toContain('quotes');
        const services = keys({ abilities: ALL, capabilities: { store: false, services: true } });
        expect(services).not.toContain('loyalty');
        expect(services).not.toContain('referrals');
        expect(services).not.toContain('recommendations');
        expect(services).toContain('reviews');
        expect(services).toContain('leads');
    });

    it('quita las secciones que quedan vacías para el rol', () => {
        const agent: Ability[] = ['viewWorkspace', 'viewAssignedContacts', 'useInbox', 'useLeads'];
        const list = visibleSections({ abilities: agent, modules: ['inbox'], capabilities: { store: true, services: false } });
        expect(list.map((s) => s.key)).toEqual(['customers', 'conversations', 'settings']);
    });

    it('la guía de estilo solo para quien administra la plataforma', () => {
        expect(visibleSections({ abilities: [...ALL, 'platformAdmin'] }, false).map((s) => s.key)).not.toContain('styleguide');
        expect(visibleSections({ abilities: [...ALL, 'platformAdmin'] }, true).map((s) => s.key)).toContain('styleguide');
    });

    it('Conexiones agrupa WhatsApp y anuncios, tu web y canales', () => {
        const connections = item('connections');
        expect(activeChild(connections, `${base}/conexiones`, base)).toBe('connectionsAccounts');
        expect(activeChild(connections, `${base}/sitios/st_1`, base)).toBe('sites');
        expect(activeChild(connections, `${base}/canales`, base)).toBe('channels');
        expect(activeChild(connections, `${base}/equipo`, base)).toBeNull();
    });

    it('el tipo de negocio decide por encima de los sitios: un negocio sin tienda no ve carritos, pedidos ni catálogo', () => {
        // Un consultorio que vende bonos con WooCommerce: los sitios dicen tienda, el negocio dice servicios.
        const services = keys({ abilities: ALL, businessType: 'services', capabilities: { store: true, services: false } });
        for (const hidden of ['loyalty', 'referrals', 'recommendations', 'watches', 'banners']) {
            expect(services).not.toContain(hidden);
        }
        expect(services).toContain('leads');
        const store = keys({ abilities: ALL, businessType: 'store', capabilities: { store: null, services: null } });
        expect(store).toContain('loyalty');
        expect(store).not.toContain('agenda');
        const both = keys({ abilities: ALL, businessType: 'both' });
        expect(both).toContain('loyalty');
        expect(both).toContain('agenda');
    });

    it('la sección de clientes usa la palabra de la vertical', () => {
        expect(sectionName(i18n.t, 'customers', 'health')).toBe('Pacientes');
        expect(sectionName(i18n.t, 'customers', 'education')).toBe('Estudiantes');
        expect(sectionName(i18n.t, 'customers', 'store')).toBe('Clientes');
        expect(sectionName(i18n.t, 'customers', null)).toBe('Clientes');
        expect(sectionName(i18n.t, 'messages', 'health')).toBe('Mensajes');
    });
});
