<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels;

/**
 * Qué puede enviar el hub por cada proveedor (hub/CLAUDE.md §8, modulos.md M07). Es lo que el hub **usa** de cada
 * proveedor, no todo lo que su API permite: lo que el producto ofrece (botones, listas, plantillas, imágenes) se decide
 * con esto, y donde no se puede, se degrada sin romper el mensaje:
 *
 * - Botones o lista (`buttons`, `lists`) → opciones numeradas en el texto («Responde 1, 2 o 3»); la respuesta escrita
 *   (el número o el texto de la opción) vuelve como si la hubiera tocado (`NumberedOptions`, `InboundHandler`).
 * - Plantilla aprobada de Meta (`templates`) → el texto compuesto con sus parámetros (`WaTemplateText`): los botones de
 *   enlace, llamada o código van escritos en el texto y las respuestas rápidas como opciones numeradas.
 * - Encabezado con imagen, video o documento (`template_media_header`) → el archivo aparte si el proveedor manda ese
 *   tipo (`media`), o se omite y queda anotado en el mensaje (`payload.degraded`).
 *
 * `window_24h`: el proveedor solo acepta texto libre dentro de las 24 h desde el último mensaje del cliente (Meta). El
 * hub igual responde con texto libre de servicio solo dentro de esa ventana en todo WhatsApp (SendPolicy).
 */
final class ChannelCapabilities
{
    public const MEDIA_TYPES = ['image', 'video', 'document', 'audio'];

    /**
     * @param  list<'image'|'video'|'document'|'audio'>  $media  archivos que se mandan sueltos (con o sin pie)
     */
    public function __construct(
        public readonly bool $official,
        public readonly bool $marketing,
        public readonly bool $templates,
        public readonly bool $freeText,
        public readonly bool $buttons,
        public readonly bool $lists,
        public readonly bool $templateButtons,
        public readonly bool $templateMediaHeader,
        public readonly array $media,
        public readonly bool $location,
        public readonly bool $contactCard,
        public readonly bool $reactions,
        public readonly bool $window24h,
        public readonly int $maxText,
        public readonly int $ratePerSecond,
    ) {}

    /** Correo y SMS: sin conversación, sin botones de WhatsApp. */
    public static function basic(bool $marketing, int $ratePerSecond, int $maxText, bool $media = false): self
    {
        return new self(
            official: true, marketing: $marketing, templates: false, freeText: true, buttons: false, lists: false,
            templateButtons: false, templateMediaHeader: false, media: $media ? ['image', 'document'] : [], location: false,
            contactCard: false, reactions: false, window24h: false, maxText: $maxText, ratePerSecond: $ratePerSecond,
        );
    }

    /** ¿Manda este tipo de archivo suelto? */
    public function sends(string $mediaType): bool
    {
        return in_array($mediaType, $this->media, true);
    }

    /** Cómo salen las opciones para elegir: botones o lista de verdad, o numeradas en el texto. */
    public function optionsAs(): string
    {
        return $this->buttons ? 'buttons' : 'numbered';
    }

    /**
     * Para la API de la SPA. Conserva las llaves de antes (`marketing`, `templates`, `free_text`, `media`, `buttons`,
     * `rate_per_second`) y suma el detalle.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'official' => $this->official,
            'marketing' => $this->marketing,
            'templates' => $this->templates,
            'free_text' => $this->freeText,
            'media' => $this->media !== [],
            'media_types' => $this->media,
            'buttons' => $this->buttons,
            'lists' => $this->lists,
            'template_buttons' => $this->templateButtons,
            'template_media_header' => $this->templateMediaHeader,
            'location' => $this->location,
            'contact_card' => $this->contactCard,
            'reactions' => $this->reactions,
            'window_24h' => $this->window24h,
            'max_text' => $this->maxText,
            'options_as' => $this->optionsAs(),
            'rate_per_second' => $this->ratePerSecond,
        ];
    }
}
