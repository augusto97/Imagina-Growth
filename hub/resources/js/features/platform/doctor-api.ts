import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/* Diagnóstico de la instalación (igr:doctor). Ruta en hub/routes/platform.php. */

export type DoctorStatus = 'ok' | 'info' | 'warning' | 'error';

export interface DoctorCheck {
    key: string;
    group: string;
    status: DoctorStatus;
    title: string;
    detail: string;
    items: string[];
    /** Dónde se arregla en Plataforma, como consulta de la pantalla (`pestana=ajustes&seccion=mail`). */
    link: string | null;
}

/** Los parámetros de Plataforma que abren dónde se arregla un punto (solo `pestana` y `seccion`), o null. */
export function doctorLinkParams(link: string | null | undefined, current: URLSearchParams): URLSearchParams | null {
    if (!link) return null;
    const target = new URLSearchParams(link);
    const tab = target.get('pestana');
    if (!tab) return null;
    const next = new URLSearchParams(current);
    next.set('pestana', tab);
    const section = target.get('seccion');
    if (section) next.set('seccion', section);
    else next.delete('seccion');
    return next;
}

export interface DoctorReport {
    status: DoctorStatus;
    groups: { key: string; name: string; checks: DoctorCheck[] }[];
    counts: Partial<Record<DoctorStatus, number>>;
    checked_at: string;
}

export function usePlatformDoctor(enabled = true) {
    return useQuery({ queryKey: ['platform', 'doctor'], queryFn: () => api.get<DoctorReport>('/api/platform/doctor'), enabled });
}

/** Primero lo que hay que arreglar: error, luego lo que falta, las notas y al final lo que está bien. */
export function sortChecks(checks: DoctorCheck[]): DoctorCheck[] {
    const order: Record<DoctorStatus, number> = { error: 0, warning: 1, info: 2, ok: 3 };
    return [...checks].sort((a, b) => order[a.status] - order[b.status]);
}

export function statusVariant(status: DoctorStatus): 'success' | 'warning' | 'destructive' | 'secondary' {
    return status === 'ok' ? 'success' : status === 'warning' ? 'warning' : status === 'error' ? 'destructive' : 'secondary';
}
