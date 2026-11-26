import { itemVisible, navContext } from '@/components/layout/nav';
import { useWorkspace } from '@/hooks/useWorkspace';

/**
 * ¿Tienda, servicios o los dos? Lo mismo que decide el menú (`workspace.business_type`; si no, las capacidades): una
 * pantalla de En tu web muestra lo de tienda (cupón, carrito, ventas) solo si aplica. Sin saberlo, los dos.
 */
export function useAudience(): { store: boolean; services: boolean } {
    const { workspace } = useWorkspace();
    const ctx = navContext(workspace);
    return { store: itemVisible({ audience: 'store' }, ctx), services: itemVisible({ audience: 'services' }, ctx) };
}
