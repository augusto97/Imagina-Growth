<?php

declare(strict_types=1);

namespace App\Domain\Connections\Providers;

use App\Domain\Connections\Enums\ConnectionProvider;

/** Resuelve el proveedor de OAuth de cada tipo de conexión. Las pruebas pueden reemplazarlo en el contenedor. */
class ProviderRegistry
{
    public function for(ConnectionProvider $provider): OAuthProvider
    {
        return match ($provider) {
            ConnectionProvider::Meta => new MetaProvider(ConnectionProvider::Meta),
            ConnectionProvider::MetaWhatsApp => new MetaProvider(ConnectionProvider::MetaWhatsApp),
            ConnectionProvider::Google => new GoogleProvider,
            ConnectionProvider::TikTok => new TikTokProvider,
        };
    }

    public function configured(ConnectionProvider $provider): bool
    {
        return $this->for($provider)->missing() === [];
    }
}
