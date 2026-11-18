import { describe, expect, it, vi } from 'vitest';
import { installDeployRecovery, STABLE_AFTER_MS } from './deployRecovery';

function setup(stored: Record<string, string> = {}) {
    const target = new EventTarget() as unknown as Window;
    const data = new Map<string, string>(Object.entries(stored));
    const storage = {
        getItem: (k: string): string | null => data.get(k) ?? null,
        setItem: (k: string, v: string) => {
            data.set(k, v);
        },
        removeItem: (k: string) => {
            data.delete(k);
        },
    };
    const reload = vi.fn();
    const timers: Array<{ fn: () => void; ms: number }> = [];
    installDeployRecovery({ target, storage, reload, setTimer: (fn, ms) => timers.push({ fn, ms }) });
    const fire = () => {
        const event = new Event('vite:preloadError', { cancelable: true });
        target.dispatchEvent(event);
        return event;
    };
    return { data, reload, timers, fire };
}

describe('recuperación tras una actualización', () => {
    it('recarga una sola vez cuando falta un archivo de la versión anterior', () => {
        const { reload, fire, data } = setup();
        const first = fire();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(first.defaultPrevented).toBe(true);
        expect(data.has('igr:deploy-reload')).toBe(true);
        const second = fire();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(second.defaultPrevented).toBe(false);
    });

    it('si ya recargó y vuelve a fallar enseguida, no entra en bucle', () => {
        const { reload, fire } = setup({ 'igr:deploy-reload': '1' });
        fire();
        expect(reload).not.toHaveBeenCalled();
    });

    it('cuando la página funciona un rato, permite recargar en la próxima actualización', () => {
        const { timers, data } = setup({ 'igr:deploy-reload': '1' });
        expect(timers).toHaveLength(1);
        const [timer] = timers;
        expect(timer?.ms).toBe(STABLE_AFTER_MS);
        timer?.fn();
        expect(data.has('igr:deploy-reload')).toBe(false);
    });
});
