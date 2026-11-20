<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * Resuelve un nombre a todas sus IP (A y AAAA) en el momento de la petición. Se inyecta para poder simular el DNS
 * en las pruebas (DNS rebinding, nombres que resuelven a redes internas).
 */
class HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (IpAddress::isIp($host)) {
            return [trim($host, '[]')];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            // Sin respuesta de dns_get_record (p. ej. /etc/hosts): el resolvedor del sistema, solo IPv4.
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
