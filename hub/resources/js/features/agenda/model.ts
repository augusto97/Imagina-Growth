import type {
    Appointment,
    AppointmentAction,
    AppointmentStatus,
    AvailabilityException,
    AvailabilityRow,
} from "./types";

/** Fecha local (YYYY-MM-DD) de un instante en una zona horaria. */
export function localDate(iso: string, tz: string): string {
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: tz,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).formatToParts(new Date(iso));
    const get = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? "";
    return `${get("year")}-${get("month")}-${get("day")}`;
}

/** Hora local «9:00 a. m.» de un instante. */
export function localTime(iso: string, tz: string, locale = "es-CO"): string {
    return new Intl.DateTimeFormat(locale, {
        timeZone: tz,
        hour: "numeric",
        minute: "2-digit",
    }).format(new Date(iso));
}

/** Suma días a una fecha YYYY-MM-DD (sin zona: calendario puro). */
export function addDays(date: string, days: number): string {
    const d = new Date(`${date}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/** Lunes de la semana de una fecha (semana ISO). */
export function weekStart(date: string): string {
    const d = new Date(`${date}T12:00:00Z`);
    const iso = (d.getUTCDay() + 6) % 7; // lunes = 0
    return addDays(date, -iso);
}

/** Desplazamiento (en minutos) de una zona horaria en un instante: Bogotá = -300. */
export function tzOffsetMinutes(tz: string, at: Date): number {
    const parts = new Intl.DateTimeFormat("en-US", {
        timeZone: tz,
        hourCycle: "h23",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    }).formatToParts(at);
    const n = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);
    const asUtc = Date.UTC(
        n("year"),
        n("month") - 1,
        n("day"),
        n("hour"),
        n("minute"),
        n("second"),
    );
    return Math.round((asUtc - at.getTime()) / 60000);
}

/** Instante UTC (ISO con Z) del comienzo de un día local en una zona horaria. */
export function dayStartUtc(date: string, tz: string): string {
    const guess = new Date(`${date}T00:00:00Z`);
    const offset = tzOffsetMinutes(tz, guess);
    return new Date(guess.getTime() - offset * 60000)
        .toISOString()
        .replace(".000Z", "Z");
}

/** Rango [desde, hasta) en UTC de la vista: un día o la semana (lunes a domingo) de `date`. */
export function viewRange(
    view: "day" | "week",
    date: string,
    tz: string,
): { from: string; to: string; days: string[] } {
    const first = view === "day" ? date : weekStart(date);
    const count = view === "day" ? 1 : 7;
    const days = Array.from({ length: count }, (_, i) => addDays(first, i));
    return {
        from: dayStartUtc(first, tz),
        to: dayStartUtc(addDays(first, count), tz),
        days,
    };
}

/** Citas agrupadas por día local, en orden. */
export function groupByDay(
    items: Appointment[],
    tz: string,
): Record<string, Appointment[]> {
    const out: Record<string, Appointment[]> = {};
    for (const a of [...items].sort((x, y) =>
        x.starts_at.localeCompare(y.starts_at),
    )) {
        const key = localDate(a.starts_at, tz);
        (out[key] ??= []).push(a);
    }
    return out;
}

const ACTIVE: AppointmentStatus[] = ["booked", "confirmed", "rescheduled"];

/** Qué se puede hacer con una cita según su estado y si ya pasó. */
export function actionsFor(
    a: Appointment,
    now = new Date(),
): AppointmentAction[] {
    if (!ACTIVE.includes(a.status)) return [];
    const started = new Date(a.starts_at) <= now;
    const out: AppointmentAction[] = [];
    if (!started && a.status !== "confirmed") out.push("confirm");
    if (started) out.push("complete", "no-show");
    if (!started) out.push("reschedule", "cancel");
    return out;
}

export function statusTone(
    status: AppointmentStatus,
): "default" | "success" | "warning" | "destructive" | "outline" | "secondary" {
    switch (status) {
        case "confirmed":
        case "completed":
            return "success";
        case "no_show":
            return "destructive";
        case "cancelled":
            return "outline";
        case "rescheduled":
            return "warning";
        default:
            return "default";
    }
}

export interface DayHours {
    weekday: number;
    open: boolean;
    ranges: { start: string; end: string }[];
}

/** Las filas de horario de una persona (o del negocio) como una semana editable de lunes a domingo. */
export function toWeek(
    rows: AvailabilityRow[],
    staffId: string | null,
): { week: DayHours[]; exceptions: AvailabilityException[] } {
    const mine = rows.filter((r) => r.staff_id === staffId);
    const week = [1, 2, 3, 4, 5, 6, 7].map((weekday) => {
        const ranges = mine
            .filter((r) => r.weekday === weekday)
            .map((r) => ({ start: r.start, end: r.end }))
            .sort((a, b) => a.start.localeCompare(b.start));
        return {
            weekday,
            open: ranges.length > 0,
            ranges:
                ranges.length > 0 ? ranges : [{ start: "09:00", end: "17:00" }],
        };
    });
    const exceptions = mine
        .flatMap((r) => r.exceptions)
        .sort((a, b) => a.date.localeCompare(b.date));
    return { week, exceptions };
}

/** La semana editable de vuelta a filas para la API; valida que cada franja cierre después de abrir y no se solape. */
export function fromWeek(week: DayHours[]): {
    rows: { weekday: number; start: string; end: string }[];
    error: { weekday: number; message: "order" | "overlap" } | null;
} {
    const rows: { weekday: number; start: string; end: string }[] = [];
    for (const day of week) {
        if (!day.open) continue;
        const ranges = [...day.ranges].sort((a, b) =>
            a.start.localeCompare(b.start),
        );
        let previousEnd = "";
        for (const r of ranges) {
            if (r.end <= r.start)
                return {
                    rows: [],
                    error: { weekday: day.weekday, message: "order" },
                };
            if (previousEnd !== "" && r.start < previousEnd)
                return {
                    rows: [],
                    error: { weekday: day.weekday, message: "overlap" },
                };
            previousEnd = r.end;
            rows.push({ weekday: day.weekday, start: r.start, end: r.end });
        }
    }
    return { rows, error: null };
}

/** Precio con separador de miles colombiano: 80000 → «$80.000». */
export function money(
    amount: string | number | null,
    currency: string,
    locale = "es-CO",
): string {
    if (amount === null || amount === "") return "—";
    const value = typeof amount === "string" ? Number(amount) : amount;
    return new Intl.NumberFormat(locale, {
        style: "currency",
        currency,
        maximumFractionDigits: currency === "COP" ? 0 : 2,
    }).format(value);
}
