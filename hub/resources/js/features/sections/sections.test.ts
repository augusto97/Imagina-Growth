import { describe, expect, it } from 'vitest';
import esCO from '@/locales/es-CO/sections.json';
import { sectionPrimary } from './SectionHomePage';

describe('página de entrada de una sección', () => {
    it('Configuración propone conectar la web mientras falte y después invitar al equipo', () => {
        expect(sectionPrimary('settings', { connections: { site: 'none', channels: [] } })).toEqual({ to: 'sitios?conectar=1', label: 'settings', ability: 'manageSites' });
        expect(sectionPrimary('settings', { connections: { site: 'pending', channels: [] } })?.to).toBe('sitios');
        expect(sectionPrimary('settings', { connections: { site: 'connected', channels: [] } })).toEqual({ to: 'equipo?invitar=1', label: 'settingsInvite', ability: 'manageMembers' });
        expect(sectionPrimary('home', {})).toBeNull();
    });

    it('cada acción principal tiene su texto', () => {
        const primary = esCO.sections.overview.primary as Record<string, string>;
        for (const key of ['customers', 'conversations', 'messages', 'onsite', 'results']) {
            expect(primary[sectionPrimary(key, {})?.label ?? '']).toBeTypeOf('string');
        }
        expect(primary.settingsInvite).toBeTypeOf('string');
    });
});
