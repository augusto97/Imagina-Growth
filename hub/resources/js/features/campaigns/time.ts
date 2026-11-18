/*
 * Fechas de campaña en la zona del espacio de trabajo: el campo
 * <input type="datetime-local"> trae la hora de reloj sin zona, y la API espera
 * un instante (ISO con zona).
 */

/** Diferencia en minutos entre la zona y UTC en ese instante (Bogotá: -300). */
export function zoneOffsetMinutes(instant: Date, timeZone: string): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(instant);
    const get = (type: string) => Number(parts.find((p) => p.type === type)?.value ?? 0);
    const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second'));
    return Math.round((asUtc - Math.floor(instant.getTime() / 1000) * 1000) / 60000);
}

/** «2026-11-24T10:00» en la zona → ISO en UTC. null si el valor no es válido. */
export function wallToIso(wall: string, timeZone: string): string | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(wall);
    if (!m) return null;
    const guess = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
    // Dos pasadas: la segunda corrige si el cambio de hora cae en medio.
    let utc = guess - zoneOffsetMinutes(new Date(guess), timeZone) * 60000;
    utc = guess - zoneOffsetMinutes(new Date(utc), timeZone) * 60000;
    return new Date(utc).toISOString();
}

/** ISO → «2026-11-24T10:00» en la zona (para el campo datetime-local). */
export function isoToWall(iso: string | null | undefined, timeZone: string): string {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const local = new Date(date.getTime() + zoneOffsetMinutes(date, timeZone) * 60000);
    return local.toISOString().slice(0, 16);
}
