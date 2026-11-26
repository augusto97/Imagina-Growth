<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\ImaginaWhats;

use RuntimeException;

/**
 * Error de la API v1 de Imagina Whats (`{error: {code, message, details}}`). Se decide por `code`, no por el texto
 * ni solo por el estado HTTP. `connection` = no hubo respuesta (red o tiempo agotado).
 */
final class ImaginaWhatsError extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Transitorio: red, 5xx, límite de velocidad u otra petición igual en curso. Lo demás no se reintenta. */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status >= 500 || in_array($this->errorCode, ['rate_limited', 'idempotency_in_progress'], true);
    }

    /** El motivo en palabras para el panel (el de Imagina Whats si no lo conocemos). */
    public function human(): string
    {
        $detail = fn (string $key): string => is_scalar($this->details[$key] ?? null) ? (string) $this->details[$key] : '';

        return match ($this->errorCode) {
            'connection' => __('No hubo respuesta de Imagina Whats: :msg', ['msg' => $this->getMessage()]),
            'unauthenticated' => __('La clave de API de Imagina Whats no es válida, venció o fue revocada. Crea una nueva en Imagina Whats → Claves de API.'),
            'missing_ability' => __('A la clave de API le falta el permiso :ability en Imagina Whats.', ['ability' => $detail('ability')]),
            'feature_not_in_plan' => __('El plan de Imagina Whats no incluye :feature.', ['feature' => $detail('feature')]),
            'insufficient_credits' => __('La cuenta de Imagina Whats no tiene créditos.'),
            'not_on_whatsapp' => __('Ese número no tiene WhatsApp.'),
            'contact_unsubscribed', 'recipient_opted_out', 'recipient_unsubscribed' => __('La persona se dio de baja en Imagina Whats.'),
            'recipient_blocked' => __('La persona bloqueó el número.'),
            'no_wa_account', 'wa_account_disabled', 'wa_account_unavailable' => __('El número de WhatsApp no está conectado en Imagina Whats. Vincúlalo otra vez allá.'),
            'rate_limited' => __('Imagina Whats pidió esperar antes de enviar más.'),
            'account_suspended', 'workspace_inactive', 'workspace_pending_review', 'sending_blocked' => __('Imagina Whats no está enviando desde esta cuenta (:code). Revisa tu cuenta allá.', ['code' => $this->errorCode]),
            'content_blocked' => __('Imagina Whats bloqueó el contenido del mensaje.'),
            default => $this->getMessage() !== '' ? $this->getMessage() : __('Imagina Whats respondió :status.', ['status' => $this->status]),
        };
    }
}
