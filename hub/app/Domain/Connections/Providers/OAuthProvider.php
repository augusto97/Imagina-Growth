<?php

declare(strict_types=1);

namespace App\Domain\Connections\Providers;

use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Support\ConnectionError;

/**
 * Un proveedor de OAuth de la plataforma. La app (client_id y secreto) es de la instalación y va en el .env;
 * los tokens son de cada espacio de trabajo y van cifrados en oauth_connections. Ningún método registra
 * tokens ni los devuelve en un mensaje de error.
 */
interface OAuthProvider
{
    public function provider(): ConnectionProvider;

    /**
     * Variables de entorno que faltan para usar este proveedor (vacío = configurado).
     *
     * @return list<string>
     */
    public function missing(): array;

    /**
     * Usos que acepta `authorizationUrl` (p. ej. `measurement`, `calendar`, `business`).
     *
     * @return list<string>
     */
    public function purposes(): array;

    public function supportsPkce(): bool;

    /** URL de la pantalla de consentimiento del proveedor. */
    public function authorizationUrl(string $state, string $purpose, ?string $codeChallenge): string;

    /** Canjea el código de la vuelta por los tokens. @throws ConnectionError */
    public function exchange(string $code, ?string $codeVerifier): TokenSet;

    /** Renueva el token de acceso. @throws ConnectionError (permanente si hay que volver a conectar) */
    public function refresh(OAuthConnection $connection): TokenSet;

    /** Revoca el acceso en el proveedor (lo mejor posible: un error no impide borrar los tokens locales). */
    public function revoke(OAuthConnection $connection): void;

    /** Comprueba que el token siga sirviendo. @throws ConnectionError */
    public function check(OAuthConnection $connection): void;
}
