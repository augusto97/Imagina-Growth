import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type ThemePreference = 'light' | 'dark' | 'system';

interface UiState {
    theme: ThemePreference;
    lastWorkspaceId: string | null;
    commandOpen: boolean;
    setTheme: (theme: ThemePreference) => void;
    setLastWorkspace: (id: string | null) => void;
    setCommandOpen: (open: boolean) => void;
}

export const useUi = create<UiState>()(
    persist(
        (set) => ({
            theme: 'system',
            lastWorkspaceId: null,
            commandOpen: false,
            setTheme: (theme) => set({ theme }),
            setLastWorkspace: (lastWorkspaceId) => set({ lastWorkspaceId }),
            setCommandOpen: (commandOpen) => set({ commandOpen }),
        }),
        {
            name: 'igr-ui',
            partialize: (s) => ({ theme: s.theme, lastWorkspaceId: s.lastWorkspaceId }),
        },
    ),
);

/** Aplica la clase .dark a <html> según la preferencia y el sistema. */
export function applyTheme(theme: ThemePreference): void {
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}
