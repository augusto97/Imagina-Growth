import type { ConsentChannel, CouponConfig, Gift, OnsiteOptions, Prize, ProductRule, Quiz, QuizQuestion, WidgetDetail, WidgetDraft, WidgetField, WidgetVariant } from './types';

/* Lógica pura del constructor de widgets (probada en onsite.test.ts). */

export const VARIANT_KEYS = ['a', 'b', 'c', 'd'] as const;

export function draftFrom(w: WidgetDetail): WidgetDraft {
    return {
        name: w.name,
        site_id: w.site_id,
        priority: w.priority,
        targeting: structuredClone(w.targeting),
        variants: structuredClone(w.variants),
        consent_config: structuredClone(w.consent_config),
        coupon_config: structuredClone(w.coupon_config),
        quiz: w.quiz ? normalizeQuiz(structuredClone(w.quiz)) : null,
        starts_at: w.starts_at,
        ends_at: w.ends_at,
        ab_auto_winner: w.ab.auto_winner,
    };
}

/** Completa las reglas de productos guardadas con una forma anterior (campos que faltan → vacíos). */
export function productRule(rule: Partial<ProductRule> | undefined): ProductRule {
    return { product_ids: rule?.product_ids ?? [], category_ids: rule?.category_ids ?? [], brands: rule?.brands ?? [], max_price: rule?.max_price ?? null, kind: rule?.kind ?? null };
}

function normalizeQuiz(quiz: Quiz): Quiz {
    return {
        ...quiz,
        outcomes: {
            ...quiz.outcomes,
            rules: quiz.outcomes.rules.map((r) => ({ ...r, products: productRule(r.products) })),
            fallback: { ...quiz.outcomes.fallback, products: productRule(quiz.outcomes.fallback.products) },
        },
    };
}

export function isPrizes(c: CouponConfig): c is { prizes: Prize[] } {
    return 'prizes' in c;
}

export function isGift(c: CouponConfig): c is Gift {
    return !('prizes' in c);
}

/** Reparte el 100 % en partes iguales (el resto va a la primera). */
export function evenWeights(count: number): number[] {
    const each = Math.floor(100 / Math.max(1, count));
    return Array.from({ length: count }, (_, i) => (i === 0 ? 100 - each * (count - 1) : each));
}

/** Nueva variante: copia de la A con los pesos repartidos por igual. */
export function addVariant(variants: WidgetVariant[]): WidgetVariant[] {
    if (variants.length >= VARIANT_KEYS.length || variants.length === 0) return variants;
    const first = variants[0] as WidgetVariant;
    const next = [...variants, { ...structuredClone(first), key: VARIANT_KEYS[variants.length] ?? 'd' }];
    const weights = evenWeights(next.length);
    return next.map((v, i) => ({ ...v, key: VARIANT_KEYS[i] ?? v.key, weight: weights[i] ?? 0 }));
}

export function removeVariant(variants: WidgetVariant[], index: number): WidgetVariant[] {
    if (variants.length <= 1) return variants;
    const rest = variants.filter((_, i) => i !== index);
    const weights = evenWeights(rest.length);
    return rest.map((v, i) => ({ ...v, key: VARIANT_KEYS[i] ?? v.key, weight: weights[i] ?? 0 }));
}

/** Probabilidad de cada premio de la ruleta (0–1). */
export function prizeOdds(prizes: Prize[]): number[] {
    const total = prizes.reduce((sum, p) => sum + Math.max(0, p.weight), 0);
    return prizes.map((p) => (total > 0 ? Math.max(0, p.weight) / total : 0));
}

/** El texto de éxito como lo verá la persona (con un código de ejemplo). */
export function fillPlaceholders(text: string, vars: { codigo?: string; premio?: string }): string {
    return text.replace(/\{\{\s*(codigo|premio)\s*\}\}/g, (_, k: 'codigo' | 'premio') => vars[k] ?? '');
}

export function linesToList(text: string): string[] {
    return text
        .split(/[\n,]/)
        .map((s) => s.trim())
        .filter((s) => s !== '');
}

/** Canales de consentimiento que van con los campos del paso: correo con el correo, WhatsApp y SMS con el teléfono. */
export function consentsForFields(channels: ConsentChannel[], fields: WidgetField[]): ConsentChannel[] {
    const keys = new Set(fields.map((f) => f.key));
    return channels.filter((c) => (c === 'email' ? keys.has('email') : keys.has('phone')));
}

export function consentText(options: OnsiteOptions | undefined, channel: string): { label: string; version: string } | null {
    const text = options?.consent_texts.find((c) => c.channel === channel);
    return text ? { label: text.label, version: text.version } : null;
}

/** La pregunta a la que lleva una opción (o null si el quiz termina). */
export function nextQuestion(quiz: Quiz, current: QuizQuestion, optionKey: string): QuizQuestion | null {
    const option = current.options.find((o) => o.key === optionKey);
    const index = quiz.questions.findIndex((q) => q.id === current.id);
    const next = option?.next;
    if (next === 'end') return null;
    if (next) return quiz.questions.find((q) => q.id === next) ?? null;
    return quiz.questions[index + 1] ?? null;
}

/** Un id libre para una pregunta nueva (q1, q2…). */
export function newQuestionId(quiz: Quiz): string {
    const used = new Set(quiz.questions.map((q) => q.id));
    let n = quiz.questions.length + 1;
    while (used.has(`q${n}`)) n++;
    return `q${n}`;
}

/** Clave legible para una opción a partir de su texto («Piel seca» → piel_seca). */
export function slugKey(label: string, fallback: string): string {
    const slug = label
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 30);
    return slug !== '' ? slug : fallback;
}

/** Quita lo que el hub no acepta antes de guardar (p. ej. el tipo de campo, que pone el hub). */
export function payload(draft: WidgetDraft): WidgetDraft {
    return { ...draft, variants: draft.variants.map((v) => ({ ...v, content: { ...v.content, fields: v.content.fields.map(({ key, required, label }) => ({ key, required, label })) } })) };
}
