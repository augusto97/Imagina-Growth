<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
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
        'twilio' => TwilioSmsDriver::class,
    ];

    /** @var array<string, string> proveedor → canal */
    public const CHANNEL = ['ses' => 'email', 'smtp' => 'email', 'meta_cloud' => 'whatsapp', 'zender' => 'whatsapp', 'twilio' => 'sms'];

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
