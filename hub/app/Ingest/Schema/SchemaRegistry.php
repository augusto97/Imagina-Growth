<?php

declare(strict_types=1);

namespace App\Ingest\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Valida eventos contra hub/resources/schemas/events (el contrato compartido
 * con el connector y el tracker). Los esquemas se compilan una vez por proceso.
 */
final class SchemaRegistry
{
    public const BASE_URI = 'https://growth.imagina.cloud/schemas/events/';

    /** Eventos que solo genera el hub (eventos.md §4.6): ningún sitio puede enviarlos. */
    public const HUB_ONLY = [
        'survey.answered', 'segment.entered', 'segment.exited', 'consent.granted', 'consent.revoked',
        'conversion.delivered', 'cart.abandoned', 'lead.created',
    ];

    private Validator $validator;

    /** @var array<string, true> */
    private array $known = [];

    public function __construct(private readonly string $path)
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(5);
        $this->validator->resolver()?->registerPrefix(self::BASE_URI, rtrim($path, '/').'/');

        foreach (glob(rtrim($path, '/').'/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (! str_starts_with($name, '_') && $name !== 'custom') {
                $this->known[$name] = true;
            }
        }
    }

    public function isKnownEvent(string $event): bool
    {
        return isset($this->known[$event]) || (str_starts_with($event, 'custom.') && strlen($event) > 7);
    }

    /** ¿Lo puede enviar un sitio por /v1/s o /v1/b? */
    public function isIngestible(string $event): bool
    {
        return $this->isKnownEvent($event) && ! in_array($event, self::HUB_ONLY, true)
            && ! str_starts_with($event, 'message.') && ! str_starts_with($event, 'flow.');
    }

    /** @return list<string> */
    public function knownEvents(): array
    {
        return array_keys($this->known);
    }

    /**
     * Valida el sobre. $envelope es el resultado de json_decode sin assoc.
     */
    public function validateEnvelope(mixed $envelope): ?string
    {
        return $this->check($envelope, self::BASE_URI.'_envelope.json');
    }

    /**
     * Valida las properties del evento con su esquema.
     */
    public function validateProperties(string $event, mixed $properties): ?string
    {
        $schema = str_starts_with($event, 'custom.') ? 'custom' : $event;
        if (! isset($this->known[$schema]) && $schema !== 'custom') {
            return 'unknown_event';
        }
        if ($schema === 'custom') {
            $encoded = json_encode($properties);
            if ($encoded === false || strlen($encoded) > 8 * 1024) {
                return 'custom.properties > 8 KB';
            }
        }

        return $this->check($properties, self::BASE_URI.$schema.'.json');
    }

    private function check(mixed $data, string $uri): ?string
    {
        $result = $this->validator->validate($data, $uri);
        if ($result->isValid()) {
            return null;
        }

        return $this->format($result->error());
    }

    private function format(?ValidationError $error): string
    {
        if ($error === null) {
            return 'invalid';
        }
        $messages = [];
        foreach ((new ErrorFormatter)->format($error, true) as $path => $errors) {
            $messages[] = ($path === '/' ? '' : $path.': ').implode('; ', (array) $errors);
        }

        return mb_substr(implode(' | ', $messages), 0, 1000);
    }
}
