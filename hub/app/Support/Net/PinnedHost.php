<?php

declare(strict_types=1);

namespace App\Support\Net;

/** Un host ya revisado y la IP pública a la que hay que conectar (null = sin fijar: local y pruebas). */
final readonly class PinnedHost
{
    public function __construct(public string $host, public int $port, public ?string $ip) {}

    /** Entrada de CURLOPT_RESOLVE («host:puerto:ip»; las IPv6 van entre corchetes). */
    public function curlResolve(): ?string
    {
        if ($this->ip === null || IpAddress::isIp($this->host)) {
            return null;
        }
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
