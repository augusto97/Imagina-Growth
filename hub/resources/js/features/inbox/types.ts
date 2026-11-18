/* Bandeja de conversaciones (M11). Formas de la API de routes/api/inbox.php. */

export type ConversationStatus = 'open' | 'pending' | 'closed';
export type AiMode = 'off' | 'assist' | 'auto';
export type InboxView = 'all' | 'mine' | 'unassigned' | 'ai';
export type Intent = 'purchase' | 'support' | 'complaint' | 'optout' | 'other';

export interface ConversationSummary {
    id: string;
    contact: { id: string; name: string } | null;
    status: ConversationStatus;
    assignee: { id: string; name: string | null } | null;
    labels: string[];
    intent: Intent | null;
    ai_mode: AiMode;
    handoff_reason: string | null;
    unread_count: number;
    last_message_at: string | null;
    awaiting_since: string | null;
    window_expires_at: string | null;
    window_open: boolean;
    first_response_seconds: number | null;
    preview?: {
        direction: 'in' | 'out';
        text: string | null;
        at: string | null;
    } | null;
}

export interface ConversationList {
    data: ConversationSummary[];
    next_before: string | null;
    counts: { mine: number; unassigned: number; unread: number };
}

export interface ChatMessage {
    id: string;
    direction: 'in' | 'out';
    kind: string;
    source: string;
    type: string;
    text: string | null;
    template: string | null;
    status: string;
    author: { kind: 'ai' | 'user'; name: string } | null;
    attachments: {
        kind: string;
        mime: string | null;
        caption: string | null;
    }[];
    failed_reason: string | null;
    at: string | null;
    delivered_at: string | null;
    read_at: string | null;
}

export interface ConversationNote {
    id: string;
    body: string;
    author: string | null;
    at: string | null;
}

export interface ConversationDetail extends ConversationSummary {
    messages: ChatMessage[];
    has_more: boolean;
    notes: ConversationNote[];
}

export interface ContactPanelData {
    contact: {
        id: string;
        name: string;
        phone: string | null;
        city: string | null;
        lifecycle_stage: string | null;
        orders_count: number;
        revenue_total: string;
        rfm_segment: string | null;
        points: number;
        tier: string | null;
        wallet: string;
    };
    orders: {
        id: string;
        number: string | null;
        status: string;
        total: string;
        currency: string | null;
        created_at: string | null;
    }[];
    cart: {
        status: string;
        items_count: number;
        subtotal: string;
        currency: string | null;
        items: { name: string | null; qty: number }[];
        restore_url: string | null;
    } | null;
    segments: string[];
    last_product_viewed: {
        product_id: string;
        name: string;
        at: string;
    } | null;
    origin: {
        ad: boolean;
        source: string | null;
        first_source: Record<string, unknown> | null;
    };
}

export type SendType = 'text' | 'template' | 'product' | 'cart_link' | 'payment_link' | 'coupon';

export interface SendBody {
    type: SendType;
    client_id: string;
    text?: string;
    template_id?: string;
    variables?: Record<string, string>;
    product_id?: string;
    items?: { product_id: string; qty: number }[];
    coupon?: string;
    kind?: 'percent' | 'fixed_cart' | 'free_shipping';
    amount?: number;
    days?: number;
}

export interface SendResult {
    status: 'queued' | 'duplicate' | 'deferred' | 'denied';
    message_id: string | null;
    until: string | null;
    reason: string | null;
    text: string | null;
    message?: string;
}

export interface QuickReply {
    id: string;
    shortcut: string;
    title: string;
    body: string;
}

export interface InboxProduct {
    id: string;
    name: string;
    sku: string | null;
    price: string | null;
    currency: string | null;
    in_stock: boolean;
    image: string | null;
    url: string | null;
}

export interface InboxTemplate {
    id: string;
    name: string;
    category: string;
    variables: string[];
}

export interface InboxAgent {
    id: string;
    name: string;
    online: boolean;
}

export interface InboxSettings {
    auto_assign: boolean;
    rules: { intent?: Intent; label?: string; user: string }[];
    push_public_key: string | null;
}

export interface Seconds {
    count: number;
    avg: number | null;
    median: number | null;
}

export interface InboxMetrics {
    days: number;
    opened: number;
    resolved: number;
    first_response: Seconds;
    resolution: Seconds;
    sales: {
        orders: number;
        revenue: string;
        ai: { orders: number; revenue: string };
    };
    ai: {
        turns: number;
        replied: number;
        handoffs: number;
        handoff_rate: number | null;
        messages: number;
    };
    agents: {
        id: string;
        name: string;
        conversations: number;
        messages: number;
        resolved: number;
        first_response: Seconds;
        orders: number;
        revenue: string;
    }[];
}
