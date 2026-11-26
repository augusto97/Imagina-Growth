import { describe, expect, it } from 'vitest';
import { workspacePath } from './prefetch';

describe('precarga al pasar el puntero', () => {
    it('reconoce las rutas del panel y su parte después del espacio de trabajo', () => {
        expect(workspacePath('/w/ws_1')).toEqual({ ws: 'ws_1', rest: '' });
        expect(workspacePath('/w/ws_1/')).toEqual({ ws: 'ws_1', rest: '' });
        expect(workspacePath('/w/ws_1/segmentos')).toEqual({ ws: 'ws_1', rest: 'segmentos' });
        expect(workspacePath('/w/ws_1/en-el-sitio/cotizador?x=1')).toEqual({ ws: 'ws_1', rest: 'en-el-sitio/cotizador' });
        expect(workspacePath('/login')).toBeNull();
    });
});
