import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { isRealtimeConnected, pollInterval, realtimeConfigured, usePrivateChannel } from './realtime';

describe('tiempo real', () => {
    it('sin VITE_REVERB_APP_KEY no se conecta y la bandeja sigue consultando rápido', () => {
        expect(realtimeConfigured()).toBe(false);
        expect(isRealtimeConnected()).toBe(false);
        expect(pollInterval(5000)).toBe(5000);
    });

    it('escuchar un canal sin Reverb configurado no hace nada ni falla', () => {
        const onEvent = vi.fn();
        const { unmount } = renderHook(() => usePrivateChannel('workspace.ws_x.inbox', 'inbox.updated', onEvent));
        unmount();
        expect(onEvent).not.toHaveBeenCalled();
    });
});
