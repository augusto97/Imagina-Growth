import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type ThemePreference = 'light' | 'dark' | 'system';

interface UiState {
    theme: ThemePreference;
    lastWorkspaceId: string | null;
    commandOpen: boolean;
    /** Grupos del menú que la persona plegó (se recuerdan entre visitas). */
    navCollapsed: string[];
    /** Barra lateral del escritorio contraída a una columna de íconos (se recuerda entre visitas). */
    sidebarCollapsed: boolean;
    /** Espacios de trabajo donde la persona ocultó «Primeros pasos» con todo listo. */
    guideHidden: string[];
    setTheme: (theme: ThemePreference) => void;
    setLastWorkspace: (id: string | null) => void;
    setCommandOpen: (open: boolean) => void;
    setNavGroupCollapsed: (key: string, collapsed: boolean) => void;
    setSidebarCollapsed: (collapsed: boolean) => void;
    setGuideHidden: (workspaceId: string, hidden: boolean) => void;
}

const toggleIn = (list: string[], key: string, on: boolean): string[] => (on ? (list.includes(key) ? list : [...list, key]) : list.filter((k) => k !== key));

export const useUi = create<UiState>()(
    persist(
        (set) => ({
            theme: 'system',
            lastWorkspaceId: null,
            commandOpen: false,
            navCollapsed: [],
            sidebarCollapsed: false,
            guideHidden: [],
            setTheme: (theme) => set({ theme }),
            setLastWorkspace: (lastWorkspaceId) => set({ lastWorkspaceId }),
            setCommandOpen: (commandOpen) => set({ commandOpen }),
            setNavGroupCollapsed: (key, collapsed) => set((s) => ({ navCollapsed: toggleIn(s.navCollapsed, key, collapsed) })),
            setSidebarCollapsed: (sidebarCollapsed) => set({ sidebarCollapsed }),
            setGuideHidden: (workspaceId, hidden) => set((s) => ({ guideHidden: toggleIn(s.guideHidden, workspaceId, hidden) })),
        }),
        {
            name: 'igr-ui',
            partialize: (s) => ({
                theme: s.theme,
                lastWorkspaceId: s.lastWorkspaceId,
                navCollapsed: s.navCollapsed,
                sidebarCollapsed: s.sidebarCollapsed,
                guideHidden: s.guideHidden,
            }),
        },
    ),
);

/** Aplica la clase .dark a <html> según la preferencia y el sistema. */
export function applyTheme(theme: ThemePreference): void {
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}
