import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/cn';
import { multiplierToPct, newField, pctToMultiplier } from './model';
import type { CalculatorKind, FieldType, QuoteDefinition, QuoteField, QuoteOption, QuotePackage } from './types';

const MISSING = 'igr:border-destructive igr:ring-1 igr:ring-destructive/40';

/**
 * Las preguntas y los precios del asistente, en lenguaje del negocio: cada opción «suma» un valor o «ajusta» el total
 * en un porcentaje (+20 %, −10 %), con su emoji para la tarjeta del cotizador público. Los precios que faltan (`missing`,
 * rutas como `fields.0.unit_price`) se marcan en rojo.
 */
export function PricesEditor({
    kind,
    definition,
    onChange,
    currency,
    missing,
}: {
    kind: CalculatorKind;
    definition: QuoteDefinition;
    onChange: (definition: QuoteDefinition) => void;
    currency: string;
    missing: readonly string[];
}) {
    const { t } = useTranslation();
    const tr = (k: string, o?: Record<string, unknown>) => t(k, o ?? {});
    const set = <K extends keyof QuoteDefinition>(k: K, v: QuoteDefinition[K]) => onChange({ ...definition, [k]: v });
    const setField = (i: number, patch: Partial<QuoteField>) =>
        set(
            'fields',
            definition.fields.map((f, j) => (j === i ? { ...f, ...patch } : f)),
        );
    const setOption = (i: number, o: number, patch: Partial<QuoteOption>) =>
        setField(i, { options: (definition.fields[i]?.options ?? []).map((x, j) => (j === o ? { ...x, ...patch } : x)) });
    const setPackage = (i: number, patch: Partial<QuotePackage>) =>
        set(
            'packages',
            definition.packages.map((p, j) => (j === i ? { ...p, ...patch } : p)),
        );
    const miss = (path: string) => (missing.includes(path) ? MISSING : undefined);
    const num = (v: string): number => (v === '' ? 0 : Number(v));

    return (
        <div className="igr:space-y-5">
            {kind === 'package' || definition.packages.length > 0 ? (
                <section className="igr:space-y-2" aria-labelledby="qw-packages">
                    <h3 id="qw-packages" className="igr:text-sm igr:font-semibold">
                        {t('quotes.editor.packages')}
                    </h3>
                    {definition.packages.map((p, i) => (
                        <div
                            key={i}
                            className="igr:grid igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:p-2 igr:sm:grid-cols-[3.5rem_minmax(0,1fr)_minmax(0,1.3fr)_9rem_auto]"
                        >
                            <EmojiInput value={p.icon} onChange={(icon) => setPackage(i, { icon })} label={t('quotes.wizard.emoji')} />
                            <Input
                                aria-label={t('quotes.editor.packageName')}
                                placeholder={t('quotes.editor.packageName')}
                                value={p.label}
                                onChange={(e) => setPackage(i, { label: e.target.value })}
                            />
                            <Input
                                aria-label={t('quotes.editor.packageDescription')}
                                placeholder={t('quotes.editor.packageDescription')}
                                value={p.description ?? ''}
                                onChange={(e) => setPackage(i, { description: e.target.value || null })}
                            />
                            <Input
                                aria-label={t('quotes.editor.price')}
                                type="number"
                                min={0}
                                value={p.price}
                                className={miss(`packages.${i}.price`)}
                                aria-invalid={missing.includes(`packages.${i}.price`) || undefined}
                                onChange={(e) => setPackage(i, { price: num(e.target.value) })}
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t('quotes.editor.remove')}
                                onClick={() =>
                                    set(
                                        'packages',
                                        definition.packages.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    ))}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            set('packages', [
                                ...definition.packages,
                                { key: `paquete_${definition.packages.length + 1}`, label: '', description: null, price: 0 },
                            ])
                        }
                    >
                        <Plus />
                        {t('quotes.editor.addPackage')}
                    </Button>
                </section>
            ) : null}

            <section className="igr:space-y-3" aria-labelledby="qw-questions">
                <h3 id="qw-questions" className="igr:text-sm igr:font-semibold">
                    {t('quotes.editor.questions')}
                </h3>
                {definition.fields.map((f, i) => (
                    <div key={i} className="igr:space-y-2.5 igr:rounded-xl igr:border igr:bg-card igr:p-3">
                        <div className="igr:grid igr:items-end igr:gap-2 igr:sm:grid-cols-[3.5rem_minmax(0,1fr)_10rem_auto]">
                            <EmojiInput value={f.icon} onChange={(icon) => setField(i, { icon })} label={t('quotes.wizard.emoji')} />
                            <div>
                                <Label htmlFor={`qw-f-${i}`} className="igr:text-xs">
                                    {t('quotes.wizard.question', { n: i + 1 })}
                                </Label>
                                <Input id={`qw-f-${i}`} className="igr:mt-1" value={f.label} onChange={(e) => setField(i, { label: e.target.value })} />
                            </div>
                            <div>
                                <Label htmlFor={`qw-ft-${i}`} className="igr:text-xs">
                                    {t('quotes.editor.type')}
                                </Label>
                                <Select
                                    id={`qw-ft-${i}`}
                                    className="igr:mt-1"
                                    value={f.type}
                                    onChange={(e) =>
                                        set(
                                            'fields',
                                            definition.fields.map((x, j) =>
                                                j === i ? { ...newField(e.target.value as FieldType, j, tr), key: x.key, label: x.label, icon: x.icon } : x,
                                            ),
                                        )
                                    }
                                >
                                    {(['number', 'select', 'checkbox'] as const).map((type) => (
                                        <option key={type} value={type}>
                                            {t(`quotes.fieldType.${type}`)}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t('quotes.editor.remove')}
                                onClick={() =>
                                    set(
                                        'fields',
                                        definition.fields.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <Input
                            aria-label={t('quotes.wizard.help')}
                            placeholder={t('quotes.wizard.helpPlaceholder')}
                            value={f.help ?? ''}
                            onChange={(e) => setField(i, { help: e.target.value || null })}
                        />
                        {f.type === 'number' ? (
                            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-5">
                                <Box label={t('quotes.editor.unitPrice', { currency })}>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={f.unit_price ?? 0}
                                        className={miss(`fields.${i}.unit_price`)}
                                        aria-invalid={missing.includes(`fields.${i}.unit_price`) || undefined}
                                        onChange={(e) => setField(i, { unit_price: num(e.target.value) })}
                                    />
                                </Box>
                                <Box label={t('quotes.editor.unit')}>
                                    <Input
                                        value={f.unit ?? ''}
                                        maxLength={20}
                                        placeholder="m²"
                                        onChange={(e) => setField(i, { unit: e.target.value || null })}
                                    />
                                </Box>
                                <Box label={t('quotes.editor.min')}>
                                    <Input type="number" value={f.min ?? 0} onChange={(e) => setField(i, { min: num(e.target.value) })} />
                                </Box>
                                <Box label={t('quotes.editor.max')}>
                                    <Input
                                        type="number"
                                        value={f.max ?? ''}
                                        onChange={(e) => setField(i, { max: e.target.value === '' ? null : Number(e.target.value) })}
                                    />
                                </Box>
                                <Box label={t('quotes.wizard.default')}>
                                    <Input
                                        type="number"
                                        value={f.default ?? ''}
                                        onChange={(e) => setField(i, { default: e.target.value === '' ? null : Number(e.target.value) })}
                                    />
                                </Box>
                            </div>
                        ) : (
                            <div className="igr:space-y-1.5">
                                <div className="igr:hidden igr:gap-2 igr:text-xs igr:text-muted-foreground igr:sm:grid igr:sm:grid-cols-[3.5rem_minmax(0,1fr)_8rem_6.5rem_auto]">
                                    <span>{t('quotes.wizard.emoji')}</span>
                                    <span>{t('quotes.editor.option')}</span>
                                    <span>{t('quotes.editor.adds', { currency })}</span>
                                    <span>{t('quotes.wizard.adjust')}</span>
                                    <span className="igr:w-9" />
                                </div>
                                {(f.options ?? []).map((o, j) => (
                                    <div
                                        key={j}
                                        className="igr:grid igr:grid-cols-[3.5rem_minmax(0,1fr)] igr:items-center igr:gap-2 igr:sm:grid-cols-[3.5rem_minmax(0,1fr)_8rem_6.5rem_auto]"
                                    >
                                        <EmojiInput value={o.icon} onChange={(icon) => setOption(i, j, { icon })} label={t('quotes.wizard.emoji')} />
                                        <Input
                                            aria-label={t('quotes.editor.option')}
                                            value={o.label}
                                            onChange={(e) => setOption(i, j, { label: e.target.value })}
                                        />
                                        <Input
                                            aria-label={t('quotes.editor.adds', { currency })}
                                            type="number"
                                            value={o.price}
                                            className={miss(`fields.${i}.options.${j}.price`)}
                                            aria-invalid={missing.includes(`fields.${i}.options.${j}.price`) || undefined}
                                            onChange={(e) => setOption(i, j, { price: num(e.target.value) })}
                                        />
                                        <Input
                                            aria-label={t('quotes.wizard.adjust')}
                                            type="number"
                                            step={5}
                                            value={multiplierToPct(o.multiplier)}
                                            className={miss(`fields.${i}.options.${j}.multiplier`)}
                                            aria-invalid={missing.includes(`fields.${i}.options.${j}.multiplier`) || undefined}
                                            onChange={(e) => setOption(i, j, { multiplier: pctToMultiplier(num(e.target.value)) })}
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('quotes.editor.remove')}
                                            disabled={(f.options ?? []).length <= 1}
                                            onClick={() => setField(i, { options: (f.options ?? []).filter((_, k) => k !== j) })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setField(i, {
                                            options: [
                                                ...(f.options ?? []),
                                                {
                                                    value: `op_${(f.options ?? []).length + 1}`,
                                                    label: t('quotes.builder.optionN', { n: (f.options ?? []).length + 1 }),
                                                    price: 0,
                                                    multiplier: 1,
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    {t('quotes.editor.addOption')}
                                </Button>
                            </div>
                        )}
                    </div>
                ))}
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    {(['number', 'select', 'checkbox'] as const).map((type) => (
                        <Button
                            key={type}
                            size="sm"
                            variant="outline"
                            onClick={() => set('fields', [...definition.fields, newField(type, definition.fields.length, tr)])}
                        >
                            <Plus />
                            {t(`quotes.fieldType.${type}`)}
                        </Button>
                    ))}
                </div>
            </section>

            <section className="igr:grid igr:gap-3 igr:rounded-xl igr:bg-muted/50 igr:p-3 igr:sm:grid-cols-3" aria-label={t('quotes.editor.pricing')}>
                <Box label={t('quotes.editor.base', { currency })} help={t('quotes.wizard.baseHelp')}>
                    <Input
                        type="number"
                        min={0}
                        value={definition.base_price}
                        className={miss('base_price')}
                        onChange={(e) => set('base_price', num(e.target.value))}
                    />
                </Box>
                <Box label={t('quotes.editor.minimum', { currency })} help={t('quotes.wizard.minimumHelp')}>
                    <Input type="number" min={0} value={definition.minimum} className={miss('minimum')} onChange={(e) => set('minimum', num(e.target.value))} />
                </Box>
                <Box label={t('quotes.editor.range')} help={t('quotes.wizard.rangeHelp')}>
                    <Input type="number" min={0} max={90} value={definition.range_pct} onChange={(e) => set('range_pct', num(e.target.value))} />
                </Box>
            </section>
        </div>
    );
}

function Box({ label, help, children }: { label: string; help?: string; children: React.ReactNode }) {
    return (
        <label className="igr:block">
            <span className="igr:text-xs igr:font-medium igr:text-foreground">{label}</span>
            <span className="igr:mt-1 igr:block">{children}</span>
            {help ? <span className="igr:mt-1 igr:block igr:text-caption igr:text-muted-foreground">{help}</span> : null}
        </label>
    );
}

/** Un emoji para la tarjeta (hasta 8 caracteres: algunos emojis son varios caracteres). */
export function EmojiInput({
    value,
    onChange,
    label,
    className,
}: {
    value: string | undefined;
    onChange: (v: string | undefined) => void;
    label: string;
    className?: string;
}) {
    return (
        <Input
            aria-label={label}
            className={cn('igr:text-center igr:text-lg', className)}
            value={value ?? ''}
            maxLength={8}
            placeholder="🙂"
            onChange={(e) => onChange(e.target.value.trim() === '' ? undefined : e.target.value.trim())}
        />
    );
}
