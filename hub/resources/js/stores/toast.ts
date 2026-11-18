import { create } from 'zustand';

export type ToastTone = 'default' | 'success' | 'error';

export interface Toast {
    id: number;
    title: string;
    description?: string;
    tone: ToastTone;
}

interface ToastState {
    toasts: Toast[];
    push: (toast: Omit<Toast, 'id'>) => void;
    dismiss: (id: number) => void;
}

let seq = 0;

export const useToasts = create<ToastState>()((set, get) => ({
    toasts: [],
    push: (toast) => {
        const id = ++seq;
        set({ toasts: [...get().toasts, { ...toast, id }].slice(-4) });
        window.setTimeout(() => get().dismiss(id), toast.tone === 'error' ? 7000 : 4000);
    },
    dismiss: (id) => set({ toasts: get().toasts.filter((t) => t.id !== id) }),
}));

export const toast = {
    success: (title: string, description?: string) => useToasts.getState().push({ title, description, tone: 'success' }),
    error: (title: string, description?: string) => useToasts.getState().push({ title, description, tone: 'error' }),
    info: (title: string, description?: string) => useToasts.getState().push({ title, description, tone: 'default' }),
};
