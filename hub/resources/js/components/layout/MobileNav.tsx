import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Menu, Search, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useUi } from '@/stores/ui';
import { Logo } from './Logo';
import { Sidebar } from './Sidebar';

/**
 * En el celular la barra lateral no cabe (docs/auditoria-2026-11.md U1): una barra superior con el menú, que abre la
 * misma navegación como panel lateral y se cierra al cambiar de página.
 */
export function MobileNav() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const setCommandOpen = useUi((s) => s.setCommandOpen);
    const location = useLocation();
    // Abierto solo en la página donde se abrió: al navegar se cierra solo.
    const [openedAt, setOpenedAt] = useState<string | null>(null);
    const open = openedAt === location.pathname;

    return (
        <DialogPrimitive.Root open={open} onOpenChange={(o) => setOpenedAt(o ? location.pathname : null)}>
            <div className="igr:sticky igr:top-0 igr:z-30 igr:flex igr:h-12 igr:items-center igr:gap-2 igr:border-b igr:bg-background/95 igr:px-3 igr:backdrop-blur igr:md:hidden">
                <DialogPrimitive.Trigger className="igr:rounded-md igr:p-1.5 igr:hover:bg-accent" aria-label={t('nav.openMenu')}>
                    <Menu className="igr:size-5" />
                </DialogPrimitive.Trigger>
                <div className="igr:min-w-0 igr:flex-1">
                    <Logo brand={workspace.branding ?? null} />
                </div>
                <button type="button" onClick={() => setCommandOpen(true)} className="igr:rounded-md igr:p-1.5 igr:hover:bg-accent" aria-label={t('command.search')}>
                    <Search className="igr:size-5" />
                </button>
            </div>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-black/40 igr:md:hidden" />
                <DialogPrimitive.Content className="igr:fixed igr:inset-y-0 igr:left-0 igr:z-50 igr:flex igr:w-72 igr:max-w-[85vw] igr:shadow-xl igr:focus:outline-none igr:md:hidden">
                    <DialogPrimitive.Title className="igr:sr-only">{t('nav.main')}</DialogPrimitive.Title>
                    <DialogPrimitive.Description className="igr:sr-only">{t('nav.main')}</DialogPrimitive.Description>
                    <div className="igr:h-full igr:w-full igr:[&>aside]:w-full">
                        <Sidebar />
                    </div>
                    <DialogPrimitive.Close
                        className="igr:absolute igr:right-2 igr:top-2.5 igr:rounded-md igr:p-1 igr:text-muted-foreground igr:hover:bg-accent"
                        aria-label={t('common.close')}
                    >
                        <X className="igr:size-4" />
                    </DialogPrimitive.Close>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
