import type { TFunction } from 'i18next';
import type { OnsiteOptions, Targeting, WidgetKind } from './types';

/*
 * Las reglas de cuándo se muestra un widget, en palabras (rediseño v2: «reglas de cuándo mostrar en palabras»). Sale
 * de la misma configuración que guarda el constructor; lo que no restringe nada no se menciona.
 */

export interface RulesSummary {
    /** Cuándo aparece («Cuando intenta salir de la página»). */
    when: string;
    /** Dónde y a quién (cada condición en su frase). */
    conditions: string[];
    /** Cuántas veces («Máximo 2 veces cada 7 días»), o null si no aplica. */
    frequency: string | null;
}

const list = (items: string[], t: TFunction) =>
    items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} ${t('onsite.rules.and')} ${items[items.length - 1] ?? ''}`;

export function describeTargeting(kind: WidgetKind, targeting: Targeting, t: TFunction, options?: Pick<OnsiteOptions, 'segments'>): RulesSummary {
    const always = kind === 'embedded_form';
    const trigger = targeting.trigger;
    let when: string;
    if (kind === 'embedded_form') {
        when = t('onsite.rules.embedded');
    } else if (kind === 'wa_widget') {
        when = t('onsite.rules.waAlways');
    } else if (trigger.type === 'time') {
        const seconds = Number(trigger.value ?? 0);
        when = seconds > 0 ? t('onsite.rules.time', { count: seconds }) : t('onsite.rules.immediate');
    } else if (trigger.type === 'scroll') {
        when = t('onsite.rules.scroll', { value: Number(trigger.value ?? 50) });
    } else {
        when = t(`onsite.rules.${trigger.type}`);
    }

    const conditions: string[] = [];
    // Dónde.
    if (targeting.page_types.length > 0) {
        conditions.push(
            t('onsite.rules.pageTypes', {
                list: list(
                    targeting.page_types.map((p) => t(`onsite.pageTypes.${p}`).toLowerCase()),
                    t,
                ),
            }),
        );
    }
    if (targeting.pages.include.length > 0) {
        conditions.push(t('onsite.rules.include', { list: targeting.pages.include.join(', ') }));
    }
    if (targeting.pages.exclude.length > 0) {
        conditions.push(t('onsite.rules.exclude', { list: targeting.pages.exclude.join(', ') }));
    }
    if (targeting.page_types.length === 0 && targeting.pages.include.length === 0 && !always) {
        conditions.unshift(t('onsite.rules.allPages'));
    }
    // A quién.
    if (targeting.devices.length > 0 && targeting.devices.length < 3) {
        conditions.push(
            t('onsite.rules.devices', {
                list: list(
                    targeting.devices.map((d) => t(`onsite.devices.${d}`).toLowerCase()),
                    t,
                ),
            }),
        );
    }
    if (targeting.new_visitors !== null) {
        conditions.push(targeting.new_visitors ? t('onsite.rules.newVisitors') : t('onsite.rules.returning'));
    }
    if (targeting.countries.length > 0) {
        conditions.push(t('onsite.rules.countries', { list: list(targeting.countries, t) }));
    }
    const utm = [targeting.utm.source, targeting.utm.medium, targeting.utm.campaign].filter((v): v is string => typeof v === 'string' && v !== '');
    if (utm.length > 0) {
        conditions.push(t('onsite.rules.utm', { list: utm.join(' / ') }));
    }
    if (targeting.segments.length > 0) {
        const names = targeting.segments.map((id) => options?.segments.find((s) => s.id === id)?.name).filter((n): n is string => typeof n === 'string');
        conditions.push(
            names.length === targeting.segments.length
                ? t('onsite.rules.segmentsNamed', { list: list(names, t) })
                : t('onsite.rules.segments', { count: targeting.segments.length }),
        );
    }
    if (targeting.cart_value_min !== null && targeting.cart_value_min !== '' && Number(targeting.cart_value_min) > 0) {
        conditions.push(t('onsite.rules.cart', { value: targeting.cart_value_min }));
    }
    if (targeting.referral) {
        conditions.push(t('onsite.rules.referral'));
    }
    if (targeting.hide_for_subscribers && kind !== 'wa_widget') {
        conditions.push(t('onsite.rules.hideSubscribers'));
    }

    let frequency: string | null = null;
    if (kind !== 'embedded_form' && kind !== 'wa_widget') {
        const { max_views: views, cooldown_days: days } = targeting.frequency;
        frequency = views <= 0 ? t('onsite.rules.noLimit') : days > 0 ? t('onsite.rules.frequency', { count: views, days }) : t('onsite.rules.frequencyTotal', { count: views });
    }
    return { when, conditions, frequency };
}

/** Todo en una frase corta para la lista o el encabezado. */
export function rulesSentence(summary: RulesSummary): string {
    return [summary.when, ...summary.conditions, ...(summary.frequency ? [summary.frequency] : [])].join(' · ');
}
