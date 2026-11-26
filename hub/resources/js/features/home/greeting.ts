/* Textos que dependen de la hora y del calendario del negocio (saludo y nombre del mes). Lógica pura con pruebas. */

/** La hora (0–23) en la zona horaria del negocio. */
export function hourIn(timeZone: string, now: Date = new Date()): number {
    try {
        const hour = new Intl.DateTimeFormat('en-US', { hour: 'numeric', hourCycle: 'h23', timeZone }).format(now);
        return Number.parseInt(hour, 10) % 24;
    } catch {
        return now.getHours();
    }
}

/** El saludo según la hora: mañana (5–11), tarde (12–18) o noche. */
export function greetingKey(hour: number): 'morning' | 'afternoon' | 'evening' {
    if (hour >= 5 && hour < 12) {
        return 'morning';
    }
    return hour >= 12 && hour < 19 ? 'afternoon' : 'evening';
}

/** «noviembre» (o «noviembre de 2026» con `year`) de una fecha AAAA-MM-DD, en el idioma del negocio. */
export function monthName(ymd: string, locale: string, year = false): string {
    const date = new Date(`${ymd.slice(0, 7)}-15T12:00:00Z`);
    if (Number.isNaN(date.getTime())) {
        return ymd;
    }
    return new Intl.DateTimeFormat(locale, { month: 'long', ...(year ? { year: 'numeric' } : {}), timeZone: 'UTC' }).format(date);
}
