import { beforeEach, describe, expect, it, vi } from 'vitest';
import { getCookie } from '../src/util';
import { beacons, boot, reset, setUrl } from './helpers';

beforeEach(async () => {
    // Deja terminar el trabajo en reposo de la prueba anterior (otra instancia del módulo).
    await new Promise((r) => setTimeout(r, 120));
    reset();
    setUrl('https://tienda.co/');
    beacons();
    delete (window as unknown as Record<string, unknown>)['clarity'];
});

describe('Microsoft Clarity', () => {
    it('identifica la grabación con el ID del navegador cuando hay consentimiento de analítica', async () => {
        const clarity = vi.fn();
        (window as unknown as Record<string, unknown>)['clarity'] = clarity;
        await boot();
        const aid = getCookie('_igr_aid');
        expect(aid).toBeTruthy();
        expect(clarity).toHaveBeenCalledWith('identify', aid, expect.any(String));
        expect(clarity).toHaveBeenCalledWith('set', 'igr_aid', aid);
        for (const call of clarity.mock.calls) expect(JSON.stringify(call)).not.toMatch(/@|ct_/);
    });

    it('no toca Clarity sin consentimiento de analítica', async () => {
        const clarity = vi.fn();
        (window as unknown as Record<string, unknown>)['clarity'] = clarity;
        await boot({ consent: { analytics: false, marketing: false, source: 'none' }, consentMode: 'strict' });
        await new Promise((r) => setTimeout(r, 50));
        expect(clarity).not.toHaveBeenCalled();
    });

    it('no falla si el sitio no tiene Clarity', async () => {
        await boot();
        expect((window as unknown as Record<string, unknown>)['igr']).toBeTruthy();
    });
});
