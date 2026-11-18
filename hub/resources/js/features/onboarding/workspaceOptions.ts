export const COUNTRIES = ['CO', 'MX', 'VE', 'PE', 'CL', 'EC', 'AR', 'PA', 'CR', 'GT', 'DO', 'US', 'ES'] as const;
export const CURRENCIES = ['COP', 'MXN', 'USD', 'PEN', 'CLP', 'ARS', 'EUR', 'VES'] as const;
export const LOCALES = ['es-CO', 'es-MX', 'es-VE'] as const;
export const INDUSTRIES = [
    'moda',
    'belleza',
    'ferreteria_pinturas',
    'mascotas',
    'alimentos',
    'tecnologia',
    'servicios_profesionales',
    'salud_bienestar',
    'educacion',
    'otra',
] as const;

/** Valores sugeridos al elegir el país (se pueden cambiar). */
export const COUNTRY_DEFAULTS: Record<string, { timezone: string; currency: string; locale: string }> = {
    CO: { timezone: 'America/Bogota', currency: 'COP', locale: 'es-CO' },
    MX: { timezone: 'America/Mexico_City', currency: 'MXN', locale: 'es-MX' },
    VE: { timezone: 'America/Caracas', currency: 'USD', locale: 'es-VE' },
    PE: { timezone: 'America/Lima', currency: 'PEN', locale: 'es-CO' },
    CL: { timezone: 'America/Santiago', currency: 'CLP', locale: 'es-CO' },
    EC: { timezone: 'America/Guayaquil', currency: 'USD', locale: 'es-CO' },
    AR: { timezone: 'America/Argentina/Buenos_Aires', currency: 'ARS', locale: 'es-CO' },
    PA: { timezone: 'America/Panama', currency: 'USD', locale: 'es-CO' },
    CR: { timezone: 'America/Costa_Rica', currency: 'USD', locale: 'es-CO' },
    GT: { timezone: 'America/Guatemala', currency: 'USD', locale: 'es-CO' },
    DO: { timezone: 'America/Santo_Domingo', currency: 'USD', locale: 'es-CO' },
    US: { timezone: 'America/New_York', currency: 'USD', locale: 'es-CO' },
    ES: { timezone: 'Europe/Madrid', currency: 'EUR', locale: 'es-CO' },
};

export const TIMEZONES = Array.from(new Set(Object.values(COUNTRY_DEFAULTS).map((d) => d.timezone).concat(['America/Monterrey', 'America/Tijuana', 'America/Cancun', 'UTC'])));
