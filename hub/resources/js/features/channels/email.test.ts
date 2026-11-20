import { describe, expect, it } from 'vitest';
import { platformEmailAllowed } from './dialogs/EmailDialog';

describe('correo por la plataforma', () => {
    it('solo se apaga cuando el plan lo dice', () => {
        expect(platformEmailAllowed({ platform_email: false })).toBe(false);
        expect(platformEmailAllowed({ platform_email: true })).toBe(true);
        expect(platformEmailAllowed({ zender_caps: { hourly: 30, daily: 200 } })).toBe(true);
        expect(platformEmailAllowed(undefined)).toBe(true);
    });
});
