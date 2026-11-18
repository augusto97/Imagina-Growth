export type Location = "onsite" | "online" | "phone";
export type AppointmentStatus =
    | "booked"
    | "confirmed"
    | "rescheduled"
    | "cancelled"
    | "completed"
    | "no_show";
export type AppointmentAction =
    | "confirm"
    | "complete"
    | "no-show"
    | "cancel"
    | "reschedule";

export interface BookingSettings {
    enabled: boolean;
    page_title: string | null;
    intro: string | null;
    ask_email: boolean;
    require_email: boolean;
    consent_channels: string[];
    whatsapp_templates: Partial<
        Record<
            "confirmation" | "reminder" | "rescheduled" | "cancelled",
            string | null
        >
    >;
    auto_no_show: boolean;
    reminder_hours: number[];
}

export interface Service {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    duration_min: number;
    buffer_min: number;
    price: string | null;
    location: Location;
    staff_ids: string[];
    min_notice_hours: number;
    max_days_ahead: number;
    active: boolean;
}

export interface Staff {
    id: string;
    name: string;
    email: string | null;
    timezone: string;
    user_id: string | null;
    active: boolean;
    calendar_connected: boolean;
}

export interface AvailabilityException {
    date: string;
    closed?: boolean;
    start?: string;
    end?: string;
}

export interface AvailabilityRow {
    id: string;
    staff_id: string | null;
    weekday: number;
    start: string;
    end: string;
    exceptions: AvailabilityException[];
}

export interface BookingOverview {
    settings: BookingSettings;
    page_url: string;
    page_qr: string;
    google_configured: boolean;
    can_manage: boolean;
    timezone: string;
    services: Service[];
    staff: Staff[];
    availability: AvailabilityRow[];
}

export interface Appointment {
    id: string;
    status: AppointmentStatus;
    channel: string;
    starts_at: string;
    ends_at: string;
    service: { id: string; name: string; location: Location } | null;
    staff_id: string | null;
    contact: {
        id: string;
        name: string | null;
        email: string | null;
        phone: string | null;
    } | null;
    lead_id: string | null;
    calendar_synced: boolean;
}

export interface Slot {
    start: string;
    end: string;
    staff_id: string | null;
}

export interface BookingMetrics {
    days: number;
    booked: number;
    upcoming: number;
    completed: number;
    no_show: number;
    cancelled: number;
    no_show_rate: number | null;
    by_service: { service: string; count: number; no_show: number }[];
    by_channel: { channel: string; count: number }[];
}
