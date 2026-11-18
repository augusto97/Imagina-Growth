import { Check, Search, X } from 'lucide-react';
import { type KeyboardEvent, useContext, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input, Select } from '@/components/ui/input';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { usePicker } from '../api';
import { BuilderContext } from './context';

/*
 * Entradas del constructor: varios valores escritos (chips), opciones
 * conmutables, números y el selector de productos o categorías del catálogo.
 */

const chip = 'igr:inline-flex igr:items-center igr:gap-1 igr:rounded igr:bg-secondary igr:px-1.5 igr:py-0.5 igr:text-xs';

export function ChipsInput({
    values,
    onChange,
    suggestions = [],
    placeholder,
    label,
    disabled = false,
    labelFor = (v) => v,
}: {
    values: string[];
    onChange: (values: string[]) => void;
    suggestions?: string[];
    placeholder?: string;
    label: string;
    disabled?: boolean;
    labelFor?: (value: string) => string;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState('');
    const listId = useId();

    const add = (raw: string) => {
        const parts = raw.split(/[,\n;]/).map((p) => p.trim()).filter((p) => p !== '' && !values.includes(p));
        if (parts.length > 0) {
            onChange([...values, ...parts]);
        }
        setDraft('');
    };
    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            add(draft);
        } else if (e.key === 'Backspace' && draft === '' && values.length > 0) {
            onChange(values.slice(0, -1));
        }
    };

    return (
        <div className="igr:flex igr:min-h-8 igr:min-w-48 igr:flex-1 igr:flex-wrap igr:items-center igr:gap-1 igr:rounded-md igr:border igr:border-input igr:bg-background igr:px-1.5 igr:py-1 igr:focus-within:ring-2 igr:focus-within:ring-ring/40">
            {values.map((v) => (
                <span key={v} className={chip}>
                    {labelFor(v)}
                    {disabled ? null : (
                        <button type="button" className="igr:text-muted-foreground igr:hover:text-foreground" onClick={() => onChange(values.filter((x) => x !== v))} aria-label={t('segments.builder.removeValue', { value: labelFor(v) })}>
                            <X className="igr:size-3" />
                        </button>
                    )}
                </span>
            ))}
            {disabled ? null : (
                <input
                    className="igr:h-6 igr:min-w-24 igr:flex-1 igr:bg-transparent igr:text-[13px] igr:outline-none igr:placeholder:text-muted-foreground"
                    value={draft}
                    list={suggestions.length > 0 ? listId : undefined}
                    placeholder={values.length === 0 ? (placeholder ?? t('segments.builder.typeAndEnter')) : ''}
                    aria-label={label}
                    onChange={(e) => {
                        const next = e.target.value;
                        // Elegir una sugerencia del datalist la agrega de una vez.
                        if (suggestions.includes(next)) {
                            add(next);
                        } else {
                            setDraft(next);
                        }
                    }}
                    onKeyDown={onKeyDown}
                    onBlur={() => draft !== '' && add(draft)}
                />
            )}
            {suggestions.length > 0 ? (
                <datalist id={listId}>
                    {suggestions.filter((s) => !values.includes(s)).map((s) => (
                        <option key={s} value={s} />
                    ))}
                </datalist>
            ) : null}
        </div>
    );
}

/** Opciones fijas que se prenden y apagan (estados, segmentos RFM, bandas). */
export function ToggleChips({ options, values, onChange, disabled = false, label }: { options: { value: string; label: string }[]; values: string[]; onChange: (values: string[]) => void; disabled?: boolean; label: string }) {
    return (
        <div role="group" aria-label={label} className="igr:flex igr:flex-wrap igr:gap-1">
            {options.map((o) => {
                const on = values.includes(o.value);
                return (
                    <button
                        key={o.value}
                        type="button"
                        disabled={disabled}
                        aria-pressed={on}
                        onClick={() => onChange(on ? values.filter((v) => v !== o.value) : [...values, o.value])}
                        className={cn(
                            'igr:inline-flex igr:h-7 igr:items-center igr:gap-1 igr:rounded-md igr:border igr:px-2 igr:text-xs igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring/40',
                            on ? 'igr:border-primary/40 igr:bg-primary/10 igr:text-primary' : 'igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                        )}
                    >
                        {on ? <Check className="igr:size-3" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

export function NumberInput({ value, onChange, label, min, max, step, suffix, disabled, className }: { value: number | string | null | undefined; onChange: (value: number | null) => void; label: string; min?: number; max?: number; step?: number | 'any'; suffix?: string; disabled?: boolean; className?: string }) {
    return (
        <span className="igr:inline-flex igr:items-center igr:gap-1.5">
            <Input
                type="number"
                inputMode="decimal"
                className={cn('igr:w-24', className)}
                value={value ?? ''}
                min={min}
                max={max}
                step={step ?? 1}
                disabled={disabled}
                aria-label={label}
                onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
            />
            {suffix ? <span className="igr:text-xs igr:text-muted-foreground">{suffix}</span> : null}
        </span>
    );
}

export function SimpleSelect<T extends string>({ value, onChange, options, label, disabled, className }: { value: T; onChange: (value: T) => void; options: { value: T; label: string; disabled?: boolean }[]; label: string; disabled?: boolean; className?: string }) {
    return (
        <Select className={cn('igr:w-auto', className)} value={value} disabled={disabled} aria-label={label} onChange={(e) => onChange(e.target.value as T)}>
            {options.map((o) => (
                <option key={o.value} value={o.value} disabled={o.disabled}>
                    {o.label}
                </option>
            ))}
        </Select>
    );
}

/** Productos o categorías del catálogo sincronizado, con búsqueda. */
export function CatalogPicker({ kind, ids, onChange }: { kind: 'products' | 'categories'; ids: string[]; onChange: (ids: string[]) => void }) {
    const { t } = useTranslation();
    // También se usa fuera del constructor (plantillas de la biblioteca).
    const ws = useWorkspace().workspace.id;
    const readOnly = useContext(BuilderContext)?.readOnly ?? false;
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const q = useDebounced(query.trim(), 250);
    const selected = usePicker(ws, kind, '', ids);
    const results = usePicker(ws, kind, q, []);
    const names = new Map((ids.length > 0 ? (selected.data ?? []) : []).map((item) => [item.id, item.name]));
    const options = (results.data ?? []).filter((item) => !ids.includes(item.id));
    const listId = useId();

    const pick = (id: string) => {
        onChange([...ids, id]);
        setQuery('');
        setActive(0);
    };
    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            setActive((i) => Math.min(i + 1, options.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const item = options[active];
            if (item) {
                pick(item.id);
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        } else if (e.key === 'Backspace' && query === '' && ids.length > 0) {
            onChange(ids.slice(0, -1));
        }
    };

    return (
        <div className="igr:relative igr:min-w-56 igr:flex-1">
            <div className="igr:flex igr:min-h-8 igr:flex-wrap igr:items-center igr:gap-1 igr:rounded-md igr:border igr:border-input igr:bg-background igr:px-1.5 igr:py-1 igr:focus-within:ring-2 igr:focus-within:ring-ring/40">
                {ids.map((id) => (
                    <span key={id} className={chip}>
                        {names.get(id) ?? (selected.isPending ? '…' : t('segments.builder.missingItem', { id }))}
                        {readOnly ? null : (
                            <button type="button" className="igr:text-muted-foreground igr:hover:text-foreground" onClick={() => onChange(ids.filter((x) => x !== id))} aria-label={t('segments.builder.removeValue', { value: names.get(id) ?? id })}>
                                <X className="igr:size-3" />
                            </button>
                        )}
                    </span>
                ))}
                {readOnly ? null : (
                    <span className="igr:flex igr:min-w-32 igr:flex-1 igr:items-center igr:gap-1">
                        <Search className="igr:size-3.5 igr:text-muted-foreground" />
                        <input
                            role="combobox"
                            aria-expanded={open}
                            aria-controls={listId}
                            aria-label={t(`segments.builder.search_${kind}`)}
                            className="igr:h-6 igr:flex-1 igr:bg-transparent igr:text-[13px] igr:outline-none igr:placeholder:text-muted-foreground"
                            placeholder={t(`segments.builder.search_${kind}`)}
                            value={query}
                            onChange={(e) => {
                                setQuery(e.target.value);
                                setOpen(true);
                                setActive(0);
                            }}
                            onFocus={() => setOpen(true)}
                            onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                            onKeyDown={onKeyDown}
                        />
                    </span>
                )}
            </div>
            {open && !readOnly ? (
                <ul id={listId} role="listbox" className="igr:absolute igr:z-20 igr:mt-1 igr:max-h-64 igr:w-full igr:overflow-auto igr:rounded-md igr:border igr:bg-popover igr:p-1 igr:shadow-lg">
                    {options.length === 0 ? (
                        <li className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{results.isFetching ? t('common.loading') : t('segments.builder.noResults')}</li>
                    ) : (
                        options.map((item, i) => (
                            <li
                                key={item.id}
                                role="option"
                                aria-selected={i === active}
                                className={cn('igr:flex igr:cursor-pointer igr:items-center igr:justify-between igr:gap-2 igr:rounded igr:px-2 igr:py-1.5 igr:text-[13px]', i === active ? 'igr:bg-accent' : 'igr:hover:bg-accent/60')}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    pick(item.id);
                                }}
                                onMouseEnter={() => setActive(i)}
                            >
                                <span className="igr:truncate">{item.name}</span>
                                <span className="igr:shrink-0 igr:text-xs igr:text-muted-foreground">{item.sku ?? `#${item.id}`}</span>
                            </li>
                        ))
                    )}
                </ul>
            ) : null}
        </div>
    );
}
