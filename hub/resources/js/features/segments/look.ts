import { CalendarCheck, List, MousePointerClick, Percent, RefreshCw, Shapes, Target, type LucideIcon } from 'lucide-react';
import type { TFunction } from 'i18next';
import type { IconTone } from '@/components/ui/icon-tile';
import type { SegmentSummary } from './types';

/*
 * Cómo se ve y cómo se explica cada grupo de clientes en la lista (rediseño v2, Clientes): un ícono por familia y, para
 * los del sistema, qué son y qué hacer con ellos en palabras («Campeones: compran seguido y gastan más»). Lógica pura,
 * probada en segments.test.tsx.
 */

export type GroupFamily = 'custom' | 'rfm' | 'library' | 'services';

/** La familia de un grupo: los propios, los RFM, los de la biblioteca de tienda y los de servicios. */
export function familyOf(s: Pick<SegmentSummary, 'is_system' | 'system_key' | 'audience'>): GroupFamily {
    if (!s.is_system || s.system_key === null) {
        return 'custom';
    }
    if (s.system_key.startsWith('rfm_')) {
        return 'rfm';
    }
    return s.audience === 'services' ? 'services' : 'library';
}

/** ¿Aplica al negocio? Los del sistema de tienda no se muestran sin tienda, ni los de servicios en una tienda. */
export function appliesTo(s: Pick<SegmentSummary, 'audience'>, store: boolean, services: boolean): boolean {
    if (s.audience === 'store') {
        return store;
    }
    if (s.audience === 'services') {
        return services;
    }
    return true;
}

export function lookOf(s: Pick<SegmentSummary, 'is_system' | 'system_key' | 'audience' | 'kind' | 'folder'>): { icon: LucideIcon; tone: IconTone } {
    switch (familyOf(s)) {
        case 'rfm':
            return { icon: Target, tone: 'violet' };
        case 'services':
            return { icon: CalendarCheck, tone: 'teal' };
        case 'library':
            return s.folder === 'Descuentos'
                ? { icon: Percent, tone: 'amber' }
                : s.folder === 'Comportamiento'
                  ? { icon: MousePointerClick, tone: 'sky' }
                  : { icon: RefreshCw, tone: 'emerald' };
        default:
            return s.kind === 'static' ? { icon: List, tone: 'slate' } : { icon: Shapes, tone: 'indigo' };
    }
}

/**
 * Qué es el grupo y qué hacer con él. Los RFM se explican con los textos de Personas (los mismos del perfil) y su
 * acción sugerida; los demás, con su descripción.
 */
export function explain(t: TFunction, s: Pick<SegmentSummary, 'is_system' | 'system_key' | 'audience' | 'description'>): { what: string | null; todo: string | null } {
    if (familyOf(s) === 'rfm' && s.system_key !== null) {
        const key = s.system_key.slice(4);
        return { what: t(`contacts.rfmHelp.${key}`, { defaultValue: '' }) || null, todo: t(`segments.rfmAction.${key}`, { defaultValue: '' }) || null };
    }
    return { what: s.description, todo: null };
}

/** La tendencia en palabras: «+23 en 30 días», «−4 en 30 días», «sin cambios» o null sin datos suficientes. */
export function trendTone(change: number | null | undefined): 'up' | 'down' | 'flat' | null {
    if (change === null || change === undefined) {
        return null;
    }
    return change > 0 ? 'up' : change < 0 ? 'down' : 'flat';
}
