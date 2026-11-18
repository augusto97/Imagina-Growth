<?php

declare(strict_types=1);

namespace App\Ingest\Pipeline;

/**
 * Quita los datos personales directos antes de escribir en ClickHouse
 * (CLAUDE.md §3.9: PII solo cifrada en MariaDB). Conserva ciudad,
 * departamento y país para la analítica geográfica.
 */
final class PiiSanitizer
{
    private const DROP = [
        'email', 'phone', 'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'postcode', 'message', 'ip', 'user_agent', 'password', 'card_number', 'name_on_card',
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function clean(string $event, array $properties): array
    {
        $clean = $this->walk($properties);
        if ($event === 'form.submitted' && isset($clean['fields']) && is_array($clean['fields'])) {
            // Campos libres de formularios: solo se conservan los mapeados no personales.
            $clean['fields'] = array_intersect_key($clean['fields'], array_flip(['city', 'service', 'budget']));
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::DROP, true)) {
                unset($data[$key]);

                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->walk($value);
            }
        }

        return $data;
    }
}
