/* Contrato de widgets.json y de los endpoints públicos (docs/api-connector.md §8.3). */

export interface Field {
    key: string;
    type?: string;
    required?: boolean;
    label?: string;
    placeholder?: string;
    options?: { key: string; label: string }[];
}

export interface ConsentText {
    channel: string;
    label: string;
    version?: string;
}

/** Paso de un formulario de varios pasos (cada paso se envía con su `step`). */
export interface Step {
    title?: string;
    body?: string;
    fields?: Field[];
    consents?: ConsentText[];
    cta?: string;
    /** Botón para saltarse el paso (dos pasos: «No, gracias» en el del WhatsApp). */
    no?: string;
}

export interface Content {
    title?: string;
    body?: string;
    fields?: Field[];
    consents?: ConsentText[];
    cta?: string;
    success?: string;
    slices?: { key: string; label: string }[];
    steps?: Step[];
    /** bar sin campos: a dónde lleva el botón. */
    url?: string;
    /** teaser: texto de la pestaña. */
    teaser?: string;
    image?: string;
    /** Letra pequeña debajo del formulario (p. ej. el aviso de privacidad). */
    footer?: string;
}

export interface Design {
    layout?: 'center' | 'corner' | 'fullscreen' | null;
    position?: 'top' | 'bottom' | 'left' | 'right' | null;
    bg?: string;
    text?: string;
    accent?: string;
    accent_text?: string;
    image?: string | null;
    image_layout?: 'text_only' | 'image_top' | 'image_left';
    /** Solo el quiz: popup o incrustado. */
    display?: 'popup' | 'embedded' | null;
}

export interface Variant {
    key: string;
    weight?: number;
    design?: Design;
    content?: Content;
}

export interface Targeting {
    trigger?: { type?: 'exit_intent' | 'time' | 'scroll' | 'immediate' | 'click'; value?: number };
    pages?: { include?: string[]; exclude?: string[] };
    page_types?: string[];
    devices?: string[];
    new_visitors?: boolean | null;
    utm?: Record<string, string>;
    referral?: boolean | null;
    countries?: string[];
    segments?: string[];
    cart_value_min?: string | null;
    hide_for_subscribers?: boolean;
    frequency?: { max_views?: number; cooldown_days?: number };
}

export interface Question {
    id: string;
    text: string;
    options: { key: string; label: string; next?: string | null }[];
}

export interface WidgetCfg {
    id: string;
    kind: string;
    slot?: string | null;
    variants?: Variant[];
    targeting?: Targeting;
    content?: Content;
    design?: Design;
    quiz?: { questions: Question[] };
    wa?: { number: string; text?: string; position?: 'left' | 'right' };
    [k: string]: unknown;
}

/** Widget ya resuelto: variante elegida y contenido combinado. */
export interface Live {
    id: string;
    kind: string;
    variant: string | null;
    content: Content;
    design: Design;
    raw: WidgetCfg;
}

export interface Product {
    id: string;
    name: string;
    price?: string;
    regular_price?: string | null;
    sale_price?: string | null;
    currency?: string;
    image?: string | null;
    url: string;
    rating_avg?: number | null;
    rating_count?: number | null;
}

export interface Coupon {
    code: string;
    description?: string;
    expires_at?: string | null;
}

export interface SubmitResult {
    ok?: boolean;
    message?: string;
    coupon?: Coupon;
    /** Regalo del amigo referido cuando el widget también da su propio cupón. */
    referral_coupon?: Coupon;
    prize?: { key: string; label: string };
    products?: Product[];
    double_opt_in?: boolean;
    errors?: Record<string, string[] | string>;
    error?: { code?: string; message?: string } | string;
}

export interface Visitor {
    known?: boolean;
    returning?: boolean;
    subscribed?: Record<string, boolean>;
    country?: string | null;
    segments?: string[];
}

export interface Res<T> {
    status: number;
    data: T;
}

/** Lo que el runtime comparte con cada paquete de widgets. */
export interface Ctx {
    base: string;
    currency: string;
    pageType: string;
    aid(): string | null;
    token(): string | undefined;
    ref(): string | null;
    track(name: 'widget.shown' | 'widget.submitted' | 'widget.closed', w: Live): void;
    /** Registra la vista, el cierre o el envío para la frecuencia. */
    mark(w: Live, what: 'view' | 'close' | 'submit'): void;
    get<T>(path: string, q?: Record<string, unknown>): Promise<Res<T>>;
    post<T>(path: string, body: Record<string, unknown>): Promise<Res<T>>;
    pageUrl(): string;
    widget(id: string): WidgetCfg | undefined;
    live(w: WidgetCfg): Live;
    consents: ConsentText[];
    /** `widgets`: el comerciante dejó activa la atribución de WhatsApp (aunque no haya números locales). */
    waRef?: { enabled?: boolean; format?: string; widgets?: boolean };
    flush(): void;
}

export type Renderer = (host: HTMLElement | null, w: Live | null, x: Ctx, slotCtx: Record<string, unknown>) => void;
