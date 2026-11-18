<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

/**
 * Consultas DNS en vivo del asistente de dominio (DKIM, SPF, DMARC). Las
 * pruebas la reemplazan en el contenedor.
 */
class DnsResolver
{
    /** @return list<string> destinos CNAME del nombre */
    public function cname(string $name): array
    {
        $records = @dns_get_record($name, DNS_CNAME);

        return is_array($records) ? array_values(array_map(fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records)) : [];
    }

    /** @return list<string> registros TXT del nombre */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        return is_array($records) ? array_values(array_map(fn ($r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))), $records)) : [];
    }
}
