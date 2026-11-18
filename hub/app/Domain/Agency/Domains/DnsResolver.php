<?php

declare(strict_types=1);

namespace App\Domain\Agency\Domains;

/** Consulta DNS (inyectable para las pruebas). */
interface DnsResolver
{
    /** @return list<string> los valores TXT del nombre */
    public function txt(string $name): array;

    /** @return list<string> los destinos CNAME del nombre, sin punto final */
    public function cname(string $name): array;
}
