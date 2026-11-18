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
            staleTime: 15_000,
            refetchOnWindowFocus: true,
            retry: (count, error) => !(isApiError(error) && error.status >= 400 && error.status < 500) && count < 2,
        },
    },
});
