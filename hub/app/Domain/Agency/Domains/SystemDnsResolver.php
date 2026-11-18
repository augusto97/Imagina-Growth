<?php

declare(strict_types=1);

namespace App\Domain\Agency\Domains;

/** DNS del sistema (`dns_get_record`); cualquier falla cuenta como «sin registros». */
final class SystemDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $out = [];
        foreach ($this->records($name, DNS_TXT) as $r) {
            $txt = $r['txt'] ?? (isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : null);
            if (is_string($txt)) {
                $out[] = $txt;
            }
        }

        return $out;
    }

    public function cname(string $name): array
    {
        $out = [];
        foreach ($this->records($name, DNS_CNAME) as $r) {
            if (is_string($r['target'] ?? null)) {
                $out[] = rtrim(strtolower($r['target']), '.');
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function records(string $name, int $type): array
    {
        $records = @dns_get_record($name, $type);

        return is_array($records) ? $records : [];
    }
}
