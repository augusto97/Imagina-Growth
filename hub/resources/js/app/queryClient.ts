import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query';
import { keys } from '@/api/queries';
import { isApiError } from '@/lib/api';

function redirectToSecurity(): void {
    if (window.location.pathname === '/seguridad') {
        return;
    }
    const next = `${window.location.pathname}${window.location.search}`;
    window.location.assign(`/seguridad?requerido=1&next=${encodeURIComponent(next)}`);
}

export const queryClient: QueryClient = new QueryClient({
    queryCache: new QueryCache({
        onError: (error) => {
            // Sesión vencida: el guard de auth manda a /login.
            if (isApiError(error, 'unauthenticated')) {
                queryClient.setQueryData(keys.me, null);
            }
            if (isApiError(error, 'two_factor_required')) {
                redirectToSecurity();
            }
        },
    }),
    mutationCache: new MutationCache({
        onError: (error) => {
            if (isApiError(error, 'unauthenticated')) {
                queryClient.setQueryData(keys.me, null);
            }
            if (isApiError(error, 'two_factor_required')) {
                redirectToSecurity();
            }
        },
    }),
    defaultOptions: {
        queries: {
            // Un minuto fresco (docs/rendimiento.md §4): volver a una pantalla o a la pestaña dentro de ese minuto pinta lo
            // que ya está en memoria sin repetir sus 3 a 7 consultas (el servidor pequeño atiende con 2 hilos de PHP). Lo
            // que tiene que estar al día lo dice su consulta (refetchInterval de la bandeja, staleTime propio) y cada
            // mutación invalida lo que cambia.
            staleTime: 60_000,
            // Diez minutos en memoria: al volver a una pantalla se ve lo último mientras se actualiza, sin skeleton.
            gcTime: 10 * 60_000,
            refetchOnWindowFocus: true,
            retry: (count, error) => !(isApiError(error) && error.status >= 400 && error.status < 500) && count < 2,
        },
    },
});
