<?php

declare(strict_types=1);

namespace App\Domain\Consent\Policy;

use Carbon\CarbonImmutable;

/**
 * Allow, Defer(until, reason) o Deny(reason) (hub/CLAUDE.md §8). Un mensaje
 * aplazado nunca se descarta en silencio: quien llamó lo vuelve a intentar en
 * `until` (cumplimiento.md §4.2, regla 1).
 */
final class PolicyDecision
{
    public const ALLOW = 'allow';

    public const DEFER = 'defer';

    public const DENY = 'deny';

    // Motivos (también los usa la revisión de cumplimiento de las campañas).
    public const NO_CONSENT = 'no_consent';

    public const NO_ADDRESS = 'no_address';

    public const SUPPRESSED = 'suppressed';

    public const UNSUBSCRIBED = 'unsubscribed';

    public const EXCLUDED_REGISTRY = 'excluded_registry';

    public const OUTSIDE_HOURS = 'outside_hours';

    public const SUNDAY = 'sunday';

    public const HOLIDAY = 'holiday';

    public const FREQUENCY_DAY = 'frequency_day';

    public const FREQUENCY_WEEK = 'frequency_week';

    public const FREQUENCY_CHANNEL_WEEK = 'frequency_channel_week';

    public const FREQUENCY_PREFERENCE = 'frequency_preference';

    public const CHANNEL_UNAVAILABLE = 'channel_unavailable';

    public const QUALITY_LOW = 'quality_low';

    public const UNOFFICIAL_MARKETING = 'unofficial_marketing';

    public const OUTSIDE_24H_WINDOW = 'outside_24h_window';

    /** Messenger e Instagram (Fase 5): Meta no permite marketing por mensaje directo. */
    public const CHANNEL_NO_MARKETING = 'channel_no_marketing';

    /** Messenger: pasaron más de 7 días desde el último mensaje del cliente (ni `HUMAN_AGENT` alcanza). */
    public const OUTSIDE_HUMAN_AGENT_WINDOW = 'outside_human_agent_window';

    private function __construct(
        public readonly string $decision,
        public readonly ?string $reason = null,
        public readonly ?CarbonImmutable $until = null,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function defer(CarbonImmutable $until, string $reason): self
    {
        return new self(self::DEFER, $reason, $until->utc());
    }

    public static function deny(string $reason): self
    {
        return new self(self::DENY, $reason);
    }

    public function allowed(): bool
    {
        return $this->decision === self::ALLOW;
    }

    public function deferred(): bool
    {
        return $this->decision === self::DEFER;
    }

    public function denied(): bool
    {
        return $this->decision === self::DENY;
    }

    /** Explicación en español para el panel (revisión de cumplimiento, detalle del mensaje). */
    public static function label(string $reason): string
    {
        return match ($reason) {
            self::NO_CONSENT => __('Sin consentimiento de marketing para el canal'),
            self::NO_ADDRESS => __('El contacto no tiene correo o teléfono para el canal'),
            self::SUPPRESSED => __('Suprimido (rebote, queja o bloqueo)'),
            self::UNSUBSCRIBED => __('Se dio de baja'),
            self::EXCLUDED_REGISTRY => __('En el registro de exclusión (RNE o REPEP)'),
            self::OUTSIDE_HOURS => __('Fuera del horario legal'),
            self::SUNDAY => __('Domingo: no se envían mensajes comerciales'),
            self::HOLIDAY => __('Festivo: no se envían mensajes comerciales'),
            self::FREQUENCY_DAY => __('Ya recibió un mensaje comercial hoy'),
            self::FREQUENCY_WEEK => __('Llegó al tope semanal de mensajes comerciales'),
            self::FREQUENCY_CHANNEL_WEEK => __('Esta semana ya se le escribió por otro canal'),
            self::FREQUENCY_PREFERENCE => __('Pidió recibir menos mensajes'),
            self::CHANNEL_UNAVAILABLE => __('No hay una cuenta activa del canal'),
            self::QUALITY_LOW => __('La calidad del número de WhatsApp bajó: se frenan las campañas de marketing'),
            self::UNOFFICIAL_MARKETING => __('La conexión no oficial de WhatsApp no permite marketing'),
            self::OUTSIDE_24H_WINDOW => __('Fuera de la ventana de 24 horas de WhatsApp'),
            default => $reason,
        };
    }
}
