<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Channels\Drivers\ImaginaWhatsDriver;
use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Channels\Drivers\MetaInstagramDriver;
use App\Domain\Messaging\Channels\Drivers\MetaMessengerDriver;
use App\Domain\Messaging\Channels\Drivers\PlatformMailDriver;
use App\Domain\Messaging\Channels\Drivers\SesDriver;
use App\Domain\Messaging\Channels\Drivers\SmtpDriver;
use App\Domain\Messaging\Channels\Drivers\TwilioSmsDriver;
use App\Domain\Messaging\Channels\Drivers\ZenderDriver;
use InvalidArgumentException;

/** Proveedor → driver (hub/CLAUDE.md §8). Infobip queda para cuando un cliente lo pida. */
final class DriverRegistry
{
    public const PROVIDERS = [
        'ses' => SesDriver::class,
        'smtp' => SmtpDriver::class,
        'meta_cloud' => MetaCloudDriver::class,
        'zender' => ZenderDriver::class,
        'imagina_whats' => ImaginaWhatsDriver::class,
        'twilio' => TwilioSmsDriver::class,
        'meta_messenger' => MetaMessengerDriver::class,
        'meta_instagram' => MetaInstagramDriver::class,
        // Respaldo de los correos transaccionales por el correo de la plataforma (lote 0.4.0): sin cuenta guardada.
        'platform_mail' => PlatformMailDriver::class,
    ];

    /** @var array<string, string> proveedor → canal */
    public const CHANNEL = ['ses' => 'email', 'smtp' => 'email', 'meta_cloud' => 'whatsapp', 'zender' => 'whatsapp', 'imagina_whats' => 'whatsapp', 'twilio' => 'sms', 'meta_messenger' => 'messenger', 'meta_instagram' => 'instagram', 'platform_mail' => 'email'];

    public function for(string $provider): ChannelDriver
    {
        $class = self::PROVIDERS[$provider] ?? throw new InvalidArgumentException("Proveedor desconocido: {$provider}");
        $driver = app($class);
        if (! $driver instanceof ChannelDriver) {
            throw new InvalidArgumentException("{$class} no es un ChannelDriver");
        }

        return $driver;
    }
}
