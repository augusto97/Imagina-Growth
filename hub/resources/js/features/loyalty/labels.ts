import type { TFunction } from 'i18next';
import { formatMoney, formatNumber } from '@/lib/format';
import type { LedgerEntry } from './types';

/* Textos del libro de movimientos (puros, para la tabla del perfil y Vitest). */

const KNOWN = ['order', 'cashback', 'signup', 'birthday', 'review', 'review_media', 'referral', 'profile', 'campaign', 'manual', 'goodwill', 'correction', 'refund', 'redeem', 'redeem_undo', 'expiry', 'merge', 'flow'] as const;

export function reasonLabel(t: TFunction, e: Pick<LedgerEntry, 'reason' | 'kind' | 'status' | 'delta'> & { parent_id?: string | null }): string {
    if (e.status === 'reversed') {
        return e.kind === 'wallet' ? t('loyalty.reasons.walletReleased') : t('loyalty.reasons.reversed');
    }
    // La confirmación de una entrada pendiente (fin del periodo de devolución, o el pedido que usó el saldo).
    if (e.status === 'confirmed' && e.parent_id && e.reason !== 'refund' && e.reason !== 'redeem_undo') {
        return e.kind === 'wallet' ? t('loyalty.reasons.walletConfirmed') : t('loyalty.reasons.confirmed', { reason: (KNOWN as readonly string[]).includes(e.reason) ? t(`loyalty.reasons.${e.reason}`) : e.reason });
    }
    if (e.kind === 'wallet' && e.reason === 'order') {
        return Number(e.delta) < 0 ? t('loyalty.reasons.walletUsed') : t('loyalty.reasons.order');
    }
    if (e.reason === 'refund' && e.kind === 'points') {
        return t('loyalty.reasons.refundPoints');
    }
    return (KNOWN as readonly string[]).includes(e.reason) ? t(`loyalty.reasons.${e.reason}`) : e.reason;
}

export function statusTone(status: LedgerEntry['status'], settled?: LedgerEntry['settled']): 'success' | 'warning' | 'outline' | 'destructive' {
    if (status === 'pending' && settled) {
        return 'outline';
    }
    return status === 'confirmed' ? 'success' : status === 'pending' ? 'warning' : status === 'expired' ? 'destructive' : 'outline';
}

/** Clave del estado que se muestra: una pendiente ya resuelta dice cómo terminó. */
export function statusKey(e: Pick<LedgerEntry, 'status' | 'settled'>): string {
    return e.status === 'pending' && e.settled ? `settled_${e.settled}` : e.status;
}

/** «+270», «−500» o «+$ 5.000». */
export function entryAmount(e: Pick<LedgerEntry, 'kind' | 'delta'>, currency: string, locale: string): string {
    const value = Number(e.delta);
    const sign = value > 0 ? '+' : value < 0 ? '−' : '';
    const abs = Math.abs(value);
    return sign + (e.kind === 'wallet' ? formatMoney(abs, currency, locale) : formatNumber(abs, locale));
}
