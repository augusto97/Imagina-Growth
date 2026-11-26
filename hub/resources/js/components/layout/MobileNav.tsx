import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Menu, Search, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';
import { preloadAssistant } from '@/features/assistant/AssistantHost';
import { useAssistant } from '@/features/assistant/model';
import { DESKTOP_QUERY, useMedia } from '@/hooks/useMedia';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useUi } from '@/stores/ui';
import { preloadCommandPalette } from './commandPaletteLoader';
import { Logo } from './Logo';
import { Sidebar } from './Sidebar';

/**
 * Celular y tableta (menos de 1024 px, docs/auditoria-2026-11.md U1 y U9): una barra superior fija con el menú, que abre
 * la misma navegación como panel lateral y se cierra al cambiar de página. Va fuera del contenedor que se desplaza
 * (`WorkspaceLayout`), así siempre se ve; desde `lg` la reemplaza la barra lateral fija.
 */
export function MobileNav() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const setCommandOpen = useUi((s) => s.setCommandOpen);
    const askAi = useAssistant((s) => s.ask);
    const location = useLocation();
    // Al pasar a escritorio (girar la tableta, agrandar la ventana) el panel se cierra: ahí manda la barra lateral fija.
    const desktop = useMedia(DESKTOP_QUERY);
    // Abierto solo en la página donde se abrió: al navegar se cierra solo.
    const [openedAt, setOpenedAt] = useState<string | null>(null);
    const open = !desktop && openedAt === location.pathname;

    return (
        <DialogPrimitive.Root open={open} onOpenChange={(o) => setOpenedAt(o ? location.pathname : null)}>
            <header className="igr:flex igr:h-12 igr:shrink-0 igr:items-center igr:gap-2 igr:border-b igr:bg-background/95 igr:px-3 igr:backdrop-blur igr:lg:hidden">
                <DialogPrimitive.Trigger className="igr:rounded-md igr:p-1.5 igr:hover:bg-accent" aria-label={t('nav.openMenu')}>
                    <Menu className="igr:size-5" aria-hidden />
                </DialogPrimitive.Trigger>
                <div className="igr:min-w-0 igr:flex-1">
                    <Logo brand={workspace.branding ?? null} />
                </div>
                {workspace.abilities.includes('useAi') ? (
                    <button
                        type="button"
                        onClick={() => askAi()}
                        onPointerDown={preloadAssistant}
                        className="igr:rounded-md igr:p-1.5 igr:text-primary igr:hover:bg-accent"
                        aria-label={t('nav.askAi')}
                    >
                        <Sparkles className="igr:size-5" aria-hidden />
                    </button>
                ) : null}
                <button
                    type="button"
                    onClick={() => setCommandOpen(true)}
                    onPointerDown={preloadCommandPalette}
                    className="igr:rounded-md igr:p-1.5 igr:hover:bg-accent"
                    aria-label={t('command.search')}
                >
                    <Search className="igr:size-5" aria-hidden />
                </button>
            </header>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-black/40 igr:lg:hidden" />
                <DialogPrimitive.Content className="igr:fixed igr:inset-y-0 igr:left-0 igr:z-50 igr:flex igr:h-dvh igr:w-72 igr:max-w-[85vw] igr:shadow-xl igr:focus:outline-none igr:lg:hidden">
                    <DialogPrimitive.Title className="igr:sr-only">{t('nav.main')}</DialogPrimitive.Title>
                    <DialogPrimitive.Description className="igr:sr-only">{t('nav.main')}</DialogPrimitive.Description>
                    <div className="igr:h-full igr:w-full igr:[&>aside]:w-full">
                        <Sidebar mode="drawer" />
                    </div>
                    <DialogPrimitive.Close
                        className="igr:absolute igr:right-2 igr:top-2.5 igr:rounded-md igr:p-1 igr:text-muted-foreground igr:hover:bg-accent"
                        aria-label={t('common.close')}
                    >
                        <X className="igr:size-4" aria-hidden />
                    </DialogPrimitive.Close>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
