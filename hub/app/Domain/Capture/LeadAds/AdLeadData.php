<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Un lead de un formulario de anuncios, con la misma forma para Meta y Google: las respuestas por clave (el nombre del
 * campo en Meta, el `column_id` en minúsculas en Google), las casillas de Meta (`custom_disclaimer_responses`), el
 * formulario, la campaña, el conjunto o grupo, el anuncio y el gclid. Los datos sensibles que un formulario pueda pedir
 * (documento de identidad, fecha de nacimiento, género…) se descartan al leerlo: la plataforma no los guarda
 * (CLAUDE.md §3.9).
 */
final class AdLeadData
{
    /** Preguntas que no se guardan (minimización: sin documentos de identidad ni datos sensibles). */
    private const SENSITIVE = '/(c[eé]dula|\bnit\b|\bdni\b|\bcpf\b|\brfc\b|curp|\bssn\b|national_id|passport|pasaporte|date_of_birth|\bdob\b|birth|nacimiento|gender|g[eé]nero|marital|military|religion|religi[oó]n)/iu';

    /**
     * @param  array<string, string>  $answers
     * @param  array<string, string>  $labels  texto de cada pregunta (Google)
     * @param  array<string, bool>  $checkboxes
     */
    public function __construct(
        public readonly string $platform,
        public readonly array $answers,
        public readonly array $labels = [],
        public readonly array $checkboxes = [],
        public readonly ?string $formId = null,
        public readonly ?string $formName = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $campaignName = null,
        public readonly ?string $adsetId = null,
        public readonly ?string $adsetName = null,
        public readonly ?string $adId = null,
        public readonly ?string $adName = null,
        public readonly ?string $gclid = null,
        public readonly ?string $network = null,       // Meta: fb | ig (o el que mande)
        public readonly bool $organic = false,
        public readonly ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * El lead que devuelve la Graph API (`GET /{leadgen_id}`).
     *
     * @param  array<string, mixed>  $lead
     */
    public static function fromMeta(array $lead): self
    {
        $answers = [];
        foreach ((array) ($lead['field_data'] ?? []) as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }
            $values = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) ($field['values'] ?? [])), fn ($v) => $v !== ''));
            $key = self::key($field['name']);
            if ($key !== '' && $values !== [] && ! self::sensitive($key)) {
                $answers[$key] = mb_substr(implode(', ', $values), 0, 1000);
            }
        }
        $checkboxes = [];
        foreach ((array) ($lead['custom_disclaimer_responses'] ?? []) as $box) {
            if (is_array($box) && is_scalar($box['checkbox_key'] ?? null)) {
                $checkboxes[self::key((string) $box['checkbox_key'])] = in_array($box['is_checked'] ?? null, [true, 1, '1', 'true'], true);
            }
        }

        return new self(
            platform: 'meta',
            answers: $answers,
            checkboxes: $checkboxes,
            formId: self::str($lead['form_id'] ?? null),
            campaignId: self::str($lead['campaign_id'] ?? null),
            campaignName: self::str($lead['campaign_name'] ?? null),
            adsetId: self::str($lead['adset_id'] ?? null),
            adsetName: self::str($lead['adset_name'] ?? null),
            adId: self::str($lead['ad_id'] ?? null),
            adName: self::str($lead['ad_name'] ?? null),
            network: self::str($lead['platform'] ?? null),
            organic: in_array($lead['is_organic'] ?? false, [true, 1, '1', 'true'], true),
            createdAt: self::time($lead['created_time'] ?? null),
        );
    }

    /**
     * El aviso del webhook de los formularios de clientes potenciales de Google Ads.
     * https://developers.google.com/google-ads/webhook/docs/implementation
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromGoogle(array $payload): self
    {
        $answers = [];
        $labels = [];
        foreach ((array) ($payload['user_column_data'] ?? []) as $column) {
            if (! is_array($column)) {
                continue;
            }
            $key = self::key((string) ($column['column_id'] ?? ($column['column_name'] ?? '')));
            $label = is_string($column['column_name'] ?? null) ? mb_substr($column['column_name'], 0, 200) : $key;
            $value = is_scalar($column['string_value'] ?? null) ? trim((string) $column['string_value']) : '';
            if ($key === '' || $value === '' || self::sensitive($key.' '.$label)) {
                continue;
            }
            $answers[$key] = mb_substr($value, 0, 1000);
            $labels[$key] = $label;
        }

        return new self(
            platform: 'google',
            answers: $answers,
            labels: $labels,
            formId: self::str($payload['form_id'] ?? null),
            campaignId: self::str($payload['campaign_id'] ?? null),
            adsetId: self::str($payload['adgroup_id'] ?? null),
            adId: self::str($payload['creative_id'] ?? null),
            gclid: self::str($payload['gcl_id'] ?? null),
        );
    }

    public function email(): ?string
    {
        foreach (['email', 'work_email'] as $key) {
            if (isset($this->answers[$key]) && filter_var($this->answers[$key], FILTER_VALIDATE_EMAIL) !== false) {
                return mb_strtolower($this->answers[$key]);
            }
        }

        return null;
    }

    public function phone(): ?string
    {
        return $this->answers['phone_number'] ?? $this->answers['phone'] ?? $this->answers['work_phone_number'] ?? $this->answers['work_phone'] ?? null;
    }

    /** @return array{0: string|null, 1: string|null} */
    public function names(): array
    {
        $first = $this->answers['first_name'] ?? null;
        $last = $this->answers['last_name'] ?? null;
        if ($first === null && isset($this->answers['full_name'])) {
            $parts = preg_split('/\s+/', trim($this->answers['full_name']), 2) ?: [''];
            $first = $parts[0] !== '' ? $parts[0] : null;
            $last = $parts[1] ?? null;
        }

        return [$first !== null ? mb_substr($first, 0, 80) : null, $last !== null ? mb_substr($last, 0, 80) : null];
    }

    /** ¿La casilla o la respuesta `$key` dice que sí? */
    public function accepted(string $key): bool
    {
        $key = self::key($key);
        if (array_key_exists($key, $this->checkboxes)) {
            return $this->checkboxes[$key];
        }
        $value = $this->answers[$key] ?? null;
        if ($value === null) {
            return false;
        }
        $norm = mb_strtolower(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)));

        return in_array($norm, ['1', 'true', 'yes', 'y', 'si', 's', 'acepto', 'autorizo', 'quiero'], true)
            || preg_match('/^(si|yes|acepto|autorizo|quiero)\b/', $norm) === 1;
    }

    /** Las respuestas que no son datos de contacto (para la calificación con IA y el panel). */
    public function message(): ?string
    {
        $skip = ['email', 'work_email', 'phone_number', 'phone', 'work_phone_number', 'work_phone', 'full_name', 'first_name', 'last_name'];
        $lines = [];
        foreach ($this->answers as $key => $value) {
            if (! in_array($key, $skip, true)) {
                $lines[] = ($this->labels[$key] ?? str_replace('_', ' ', $key)).': '.$value;
            }
        }

        return $lines !== [] ? mb_substr(implode("\n", $lines), 0, 3000) : null;
    }

    /** @return array<string, mixed> lo que se guarda cifrado en `lead_ad_leads.data` */
    public function toArray(): array
    {
        return array_filter([
            'answers' => $this->answers,
            'labels' => $this->labels !== [] ? $this->labels : null,
            'checkboxes' => $this->checkboxes !== [] ? $this->checkboxes : null,
            'form_name' => $this->formName,
            'campaign_name' => $this->campaignName,
            'adset_name' => $this->adsetName,
            'ad_name' => $this->adName,
            'network' => $this->network,
            'organic' => $this->organic ?: null,
            'gclid' => $this->gclid,
            'created_time' => $this->createdAt?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    private static function sensitive(string $text): bool
    {
        return preg_match(self::SENSITIVE, str_replace('_', ' ', $text)) === 1;
    }

    public static function key(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(trim($name))), '_'), 0, 80);
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 200) : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        try {
            return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
