import '@fontsource-variable/inter';
import '@/lib/i18n';
import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import { queryClient } from '@/app/queryClient';
import { router } from '@/app/router';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { applyTheme, useUi } from '@/stores/ui';

function ThemeSync() {
    const theme = useUi((s) => s.theme);
    useEffect(() => {
        applyTheme(theme);
        if (theme !== 'system') {
            return;
        }
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => applyTheme('system');
        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, [theme]);
    return null;
}

const root = document.getElementById('root');
if (root) {
    createRoot(root).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <TooltipProvider>
                    <ThemeSync />
                    <RouterProvider router={router} />
                    <Toaster />
                </TooltipProvider>
            </QueryClientProvider>
        </StrictMode>,
    );
}
