import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import type { CountOperator, FieldOption, Scalar, Window } from '../types';
import { useBuilder } from './context';
import { ChipsInput, NumberInput, SimpleSelect, ToggleChips } from './inputs';

/*
 * Operador y valor según el tipo del campo (modulos.md M04), la ventana de
 * tiempo y el conteo («al menos 2 veces», «nunca»).
 */

const NO_VALUE = ['is_set', 'is_not_set', 'this_month', 'is_true', 'is_false'];
const MULTI = ['in', 'not_in', 'contains_any', 'contains_all', 'not_contains'];
const DAYS = ['within_last_days', 'more_than_days_ago', 'within_next_days'];
const MONEY_FIELDS = ['total_spent', 'aov', 'predicted_clv_12m'];

type Value = Scalar | Scalar[] | null | undefined;

/** Valor inicial al cambiar de operador, para no mandar un valor del tipo equivocado. */
export function defaultValue(type: FieldOption['type'], operator: string): Value {
    if (NO_VALUE.includes(operator)) {
        return undefined;
    }
    if (MULTI.includes(operator)) {
        return [];
    }
    if (DAYS.includes(operator)) {
        return 30;
    }
    if (operator === 'between') {
        return type === 'date' ? ['', ''] : [0, 0];
    }
    if (type === 'number') {
        return 0;
    }
    return '';
}

export function useEnumLabel() {
    const { t } = useTranslation();
    const { options } = useBuilder();
    return (field: string, value: string): string => {
        if (field === 'rfm_segment') {
            return options.rfm_segments.find((s) => s.key === value)?.name ?? value;
        }
        if (field === 'preferred_channel') {
            return t(`segments.channels.${value}`, { defaultValue: value });
        }
        return t(`segments.enum.${field}.${value}`, { defaultValue: value });
    };
}

export function suggestionsFor(field: string, values: ReturnType<typeof useBuilder>['options']['values']): string[] {
    switch (field) {
        case 'city':
            return values.city;
        case 'region_code':
            return values.region_code;
        case 'country':
            return values.country;
        case 'hdyhau':
            return values.hdyhau;
        default:
            return [];
    }
}

export function OperatorValue({ field, option, operator, value, onChange }: { field: string; option: FieldOption; operator: string; value: Value; onChange: (next: { operator: string; value: Value }) => void }) {
    const { t } = useTranslation();
    const { options, readOnly, currency } = useBuilder();
    const enumLabel = useEnumLabel();
    const setValue = (v: Value) => onChange({ operator, value: v });
    const money = MONEY_FIELDS.includes(field) ? currency : undefined;
    const valueLabel = t('segments.builder.value');

    const operatorSelect = (
        <SimpleSelect
            value={operator}
            label={t('segments.builder.operator')}
            disabled={readOnly}
            onChange={(op) => onChange({ operator: op, value: defaultValue(option.type, op) })}
            options={option.operators.map((op) => ({ value: op, label: t(`segments.operators.${option.type === 'tags' ? 'tags_' : ''}${op}`) }))}
        />
    );

    let editor: ReactNode;
    if (NO_VALUE.includes(operator)) {
        editor = null;
    } else if (MULTI.includes(operator)) {
        const list = Array.isArray(value) ? value.map(String) : [];
        editor =
            option.values !== null && option.values.length > 0 ? (
                <ToggleChips label={valueLabel} disabled={readOnly} values={list} onChange={setValue} options={option.values.map((v) => ({ value: v, label: enumLabel(field, v) }))} />
            ) : (
                <ChipsInput label={valueLabel} disabled={readOnly} values={list} onChange={setValue} suggestions={suggestionsFor(field, options.values)} />
            );
    } else if (DAYS.includes(operator)) {
        editor = <NumberInput label={t('segments.builder.days')} disabled={readOnly} min={1} max={3650} value={typeof value === 'number' ? value : 30} onChange={(n) => setValue(n ?? 1)} suffix={t('segments.builder.daysSuffix')} />;
    } else if (operator === 'between') {
        const pair = Array.isArray(value) ? value : ['', ''];
        if (option.type === 'date') {
            editor = (
                <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                    <Input type="date" className="igr:w-36" aria-label={t('segments.builder.from')} disabled={readOnly} value={String(pair[0] ?? '')} onChange={(e) => setValue([e.target.value, pair[1] ?? ''])} />
                    <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.and')}</span>
                    <Input type="date" className="igr:w-36" aria-label={t('segments.builder.to')} disabled={readOnly} value={String(pair[1] ?? '')} onChange={(e) => setValue([pair[0] ?? '', e.target.value])} />
                </span>
            );
        } else {
            editor = (
                <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                    <NumberInput label={t('segments.builder.from')} disabled={readOnly} step="any" value={pair[0]} onChange={(n) => setValue([n ?? 0, pair[1] ?? 0])} />
                    <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.and')}</span>
                    <NumberInput label={t('segments.builder.to')} disabled={readOnly} step="any" value={pair[1]} onChange={(n) => setValue([pair[0] ?? 0, n ?? 0])} suffix={money} />
                </span>
            );
        }
    } else if (option.type === 'date') {
        editor = <Input type="date" className="igr:w-40" aria-label={valueLabel} disabled={readOnly} value={typeof value === 'string' ? value : ''} onChange={(e) => setValue(e.target.value)} />;
    } else if (option.type === 'number') {
        editor = <NumberInput label={valueLabel} disabled={readOnly} step="any" value={typeof value === 'number' || typeof value === 'string' ? value : null} onChange={(n) => setValue(n ?? 0)} suffix={money} className="igr:w-32" />;
    } else if (option.type === 'enum' && option.values !== null) {
        editor = (
            <SimpleSelect
                value={typeof value === 'string' ? value : ''}
                label={valueLabel}
                disabled={readOnly}
                onChange={setValue}
                options={[{ value: '', label: t('segments.builder.choose'), disabled: true }, ...option.values.map((v) => ({ value: v, label: enumLabel(field, v) }))]}
            />
        );
    } else {
        editor = <Input className="igr:w-48" aria-label={valueLabel} disabled={readOnly} value={typeof value === 'string' ? value : String(value ?? '')} onChange={(e) => setValue(e.target.value)} placeholder={t('segments.builder.writeValue')} />;
    }

    return (
        <>
            {operatorSelect}
            {editor}
        </>
    );
}

export function WindowEditor({ window, onChange, allowEver = true }: { window: Window | undefined; onChange: (w: Window) => void; allowEver?: boolean }) {
    const { t } = useTranslation();
    const { readOnly } = useBuilder();
    const w: Window = window ?? { kind: 'ever' };
    const kinds: Window['kind'][] = allowEver ? ['ever', 'last_days', 'this_month', 'between'] : ['last_days', 'this_month', 'between'];
    const change = (kind: Window['kind']) => {
        if (kind === 'last_days') {
            onChange({ kind, days: 30 });
        } else if (kind === 'between') {
            const today = new Date().toISOString().slice(0, 10);
            onChange({ kind, from: today, to: today });
        } else {
            onChange({ kind });
        }
    };

    return (
        <span className="igr:inline-flex igr:flex-wrap igr:items-center igr:gap-1.5">
            <SimpleSelect value={w.kind} label={t('segments.builder.window')} disabled={readOnly} onChange={change} options={kinds.map((k) => ({ value: k, label: t(`segments.window.${k}`) }))} />
            {w.kind === 'last_days' ? (
                <NumberInput label={t('segments.builder.days')} disabled={readOnly} min={1} max={3650} value={w.days} onChange={(n) => onChange({ kind: 'last_days', days: Math.max(1, Math.round(n ?? 1)) })} suffix={t('segments.builder.daysSuffix')} />
            ) : null}
            {w.kind === 'between' ? (
                <>
                    <Input type="date" className="igr:w-36" aria-label={t('segments.builder.from')} disabled={readOnly} value={w.from} onChange={(e) => onChange({ ...w, from: e.target.value })} />
                    <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.and')}</span>
                    <Input type="date" className="igr:w-36" aria-label={t('segments.builder.to')} disabled={readOnly} value={w.to} onChange={(e) => onChange({ ...w, to: e.target.value })} />
                </>
            ) : null}
        </span>
    );
}

const COUNT_OPS: CountOperator[] = ['ever', 'never', 'count_gte', 'count_lte', 'count_eq'];

export function CountEditor({ operator, value, unit = 'times', onChange }: { operator: CountOperator; value: number | undefined; unit?: 'times' | 'sessions' | 'days'; onChange: (next: { operator: CountOperator; value?: number }) => void }) {
    const { t } = useTranslation();
    const { readOnly } = useBuilder();
    const counted = operator !== 'ever' && operator !== 'never';
    return (
        <span className="igr:inline-flex igr:items-center igr:gap-1.5">
            <SimpleSelect
                value={operator}
                label={t('segments.builder.frequency')}
                disabled={readOnly}
                onChange={(op) => onChange(op === 'ever' || op === 'never' ? { operator: op } : { operator: op, value: value ?? 1 })}
                options={COUNT_OPS.map((op) => ({ value: op, label: t(`segments.countOps.${op}`) }))}
            />
            {counted ? (
                <NumberInput label={t('segments.builder.times')} disabled={readOnly} min={0} max={100000} value={value ?? 1} onChange={(n) => onChange({ operator, value: Math.max(0, Math.round(n ?? 0)) })} suffix={t(`segments.units.${unit}`, { count: value ?? 1 })} className="igr:w-20" />
            ) : null}
        </span>
    );
}
