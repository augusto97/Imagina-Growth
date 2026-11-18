/* Tipos de la API de reseñas (routes/api/reviews.php). */

export type ReviewStatus = 'pending' | 'published' | 'rejected';
export type ReviewSource = 'request' | 'widget' | 'import' | 'woocommerce';
export type RejectReason = 'offensive' | 'spam' | 'personal_data' | 'off_topic' | 'duplicate' | 'other';
export type ReviewFlag = 'offensive' | 'spam' | 'personal_data';

export const REJECT_REASONS: RejectReason[] = ['offensive', 'spam', 'personal_data', 'off_topic', 'duplicate', 'other'];

export interface ReviewMedia {
    url: string;
    type: 'image' | 'video';
    thumb: string | null;
}

export interface Review {
    id: string;
    status: ReviewStatus;
    rating: number;
    title: string | null;
    body: string | null;
    author: string | null;
    verified: boolean;
    source: ReviewSource;
    product: { id: string; name: string | null; image: string | null } | null;
    contact_id: string | null;
    order: { id: string; number: string } | null;
    media: ReviewMedia[];
    flags: ReviewFlag[];
    flag_details: Partial<Record<ReviewFlag, string[]>>;
    reply: string | null;
    replied_at: string | null;
    reject_reason: RejectReason | null;
    sync_status: 'pending' | 'synced' | 'failed' | 'skipped' | null;
    sync_error: string | null;
    on_store: boolean;
    created_at: string | null;
    published_at: string | null;
    moderated_at: string | null;
}

export interface Page<T> {
    data: T[];
    meta: { total: number; page: number; per_page: number };
}

export interface ReviewFilters {
    status?: ReviewStatus | '';
    rating?: number | '';
    source?: ReviewSource | '';
    media?: boolean;
    flagged?: boolean;
    q?: string;
    page?: number;
}

export interface ReviewSummary {
    reviews: {
        pending: number;
        published: number;
        rejected: number;
        flagged_pending: number;
        with_media: number;
        avg_rating: number | null;
        distribution: Record<'5' | '4' | '3' | '2' | '1', number>;
    };
    requests: { days: number; scheduled: number; sent: number; completed: number; skipped: number; completion_rate: number | null };
}

export type RequestStatus = 'scheduled' | 'sent' | 'completed' | 'skipped' | 'expired';

export interface ReviewRequestRow {
    id: string;
    status: RequestStatus;
    channel: 'whatsapp' | 'email' | 'sms' | null;
    kind: 'marketing' | 'utility';
    skip_reason: string | null;
    order: { id: string; number: string };
    contact_id: string;
    products: number;
    scheduled_for: string | null;
    sent_at: string | null;
    completed_at: string | null;
}

export type Channel = 'whatsapp' | 'email' | 'sms';

export interface ReviewSettings {
    request: {
        enabled: boolean;
        trigger: 'completed' | 'paid';
        days: number;
        category_days: { category_id: string; days: number }[];
        channels: Channel[];
        email_template_id: string | null;
        whatsapp_template_id: string | null;
        sms_template_id: string | null;
        skip_reviewed: boolean;
        kind: 'marketing' | 'utility';
        reclassify_reason: string | null;
        link_days: number;
    };
    moderation: { mode: 'manual' | 'auto' };
    google_review_url: string | null;
    publish_to_store: boolean;
    reply_author: string | null;
}

export interface TemplateOption {
    id: string;
    name: string;
    category: string;
    library_key: string | null;
    status: string;
}

export interface ReviewSettingsResponse {
    settings: ReviewSettings;
    options: {
        templates: Record<Channel, TemplateOption[]>;
        categories: { id: string; name: string }[];
        loyalty_active: boolean;
        rewards: { review: number; media: number };
        photos_supported: boolean;
        can_reclassify: boolean;
    };
}

export type SurveyKind = 'nps' | 'csat';

export interface SurveySettings {
    id: string;
    kind: SurveyKind;
    status: 'active' | 'paused';
    question: string;
    comment_label: string;
    thanks: string;
    scale: { min: number; max: number };
    after: 'completed' | 'paid';
    days: number;
    channels: Channel[];
    email_template_id: string | null;
    whatsapp_template_id: string | null;
    sms_template_id: string | null;
    alert_threshold: number;
    alert_emails: string[];
}

export type SurveyCategory = 'promoter' | 'passive' | 'detractor' | 'satisfied' | 'neutral' | 'unsatisfied';

export interface SurveyReport {
    kind: SurveyKind;
    days: number;
    responses: number;
    sent: number;
    response_rate: number | null;
    score: number | null;
    categories: Partial<Record<SurveyCategory, number>>;
    distribution: Record<string, number>;
    trend: { week: string; responses: number; score: number }[];
    recent: { id: string; score: number; category: SurveyCategory; comment: string | null; contact_id: string | null; order: string | null; created_at: string | null }[];
}

export interface ImportResult {
    rows: number;
    created: number;
    duplicates: number;
    errors: { row: number; message: string }[];
}
