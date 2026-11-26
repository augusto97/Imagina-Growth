import type { TFunction } from 'i18next';
import type { SendMode, SendWindow } from './types';

/*
 * La ventana legal en palabras para el paso «¿Cuándo?» y la revisión: «Se enviará el lunes 7 de diciembre a las
 * 7:00 a. m. porque el sábado solo se puede enviar de 8:00 a 15:00 y el domingo no se envían mensajes comerciales».
 * La hora y los motivos los calcula el servidor (GET campaigns/send-window, SendWindowExplainer).
 */

export interface WindowSentence {
    moved: boolean;
    titleKey: string;
    titleVars: Record<string, string>;
    /** Los motivos ya escritos, en orden. */
    because: string[];
    perContact: boolean;
}

/** «lunes, 7 de diciembre» de un instante en la zona dada. */
export function dayLabel(iso: string, locale: string, timeZone: string): string {
    return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone }).format(new Date(iso));
}

/** «lunes, 7 de diciembre» de una fecha Y-m-d (sin zona). */
export function dateLabel(ymd: string, locale: string): string {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(y ?? 2026, (m ?? 1) - 1, d ?? 1, 12)));
}

export function timeLabel(iso: string, locale: string, timeZone: string): string {
    return new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZone }).format(new Date(iso));
}

/** «07:00» → «7:00». */
export function hourLabel(hhmm: string): string {
    return hhmm.replace(/^0(\d)/, '$1');
}

export function describeWindow(t: TFunction, w: SendWindow, locale: string, mode: SendMode): WindowSentence {
    const when = t('campaigns.window.at', { day: dayLabel(w.sends_at, locale, w.timezone), time: timeLabel(w.sends_at, locale, w.timezone) });
    const requestedDay = dayLabel(w.requested_at, locale, w.timezone);
    const because: string[] = [];
    if (w.moved) {
        switch (w.reason) {
            case 'past':
                because.push(t('campaigns.window.reasons.past'));
                break;
            case 'sunday':
                because.push(t('campaigns.window.reasons.sunday', { day: requestedDay }));
                break;
            case 'holiday':
                because.push(w.holiday ? t('campaigns.window.reasons.holidayNamed', { day: requestedDay, name: w.holiday }) : t('campaigns.window.reasons.holiday', { day: requestedDay }));
                break;
            case 'outside_hours':
                because.push(
                    w.requested_window
                        ? t('campaigns.window.reasons.hours', { day: requestedDay, from: hourLabel(w.requested_window[0]), to: hourLabel(w.requested_window[1]) })
                        : t('campaigns.window.reasons.closed', { day: requestedDay }),
                );
                break;
            default:
                break;
        }
        for (const s of w.skipped) {
            const day = dateLabel(s.date, locale);
            because.push(
                s.reason === 'holiday'
                    ? s.holiday
                        ? t('campaigns.window.reasons.holidayNamed', { day, name: s.holiday })
                        : t('campaigns.window.reasons.holiday', { day })
                    : s.reason === 'sunday'
                      ? t('campaigns.window.reasons.sunday', { day })
                      : t('campaigns.window.reasons.closed', { day }),
            );
        }
    }
    const list = new Intl.ListFormat(locale, { type: 'conjunction' });
    const reasons = because.length > 0 ? [list.format(because)] : [];
    if (mode === 'now') {
        return { moved: w.moved, titleKey: w.moved ? 'campaigns.window.nowMoved' : 'campaigns.window.nowOk', titleVars: { when }, because: reasons, perContact: w.per_contact };
    }
    return { moved: w.moved, titleKey: w.moved ? 'campaigns.window.moved' : 'campaigns.window.ok', titleVars: { when }, because: reasons, perContact: w.per_contact };
}
