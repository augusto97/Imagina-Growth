<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

/**
 * Lo que el job de envío le pasa al driver: el destino y el contenido ya
 * renderizado, con los enlaces rastreados. `headers` son cabeceras extra del
 * correo (List-Unsubscribe y List-Unsubscribe-Post en el marketing).
 * `interactive` es el objeto de botones o lista de WhatsApp (WhatsAppInteractive);
 * los drivers que no lo muestran mandan `text`, que ya trae las opciones.
 */
final class OutgoingMessage
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, mixed>>  $waComponents
     * @param  array<string, mixed>|null  $interactive
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $to,
        public readonly bool $isMarketing,
        public readonly ?string $subject = null,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly array $headers = [],
        public readonly ?string $waTemplateName = null,
        public readonly ?string $waLanguage = null,
        public readonly array $waComponents = [],
        public readonly ?string $messagingTag = null, // Fase 5: Messenger fuera de la ventana de 24 h (`HUMAN_AGENT`, solo personas)
        public readonly ?array $interactive = null,
    ) {}
}
