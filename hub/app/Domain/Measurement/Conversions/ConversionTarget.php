<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;

/**
 * Un endpoint de una plataforma (Meta CAPI, GA4, Data Manager, TikTok). Cada
 * uno se entrega y se reintenta por separado.
 */
interface ConversionTarget
{
    public function key(): string;

    public function platform(): Platform;

    /** ¿Este destino está configurado para enviar este evento por este endpoint? */
    public function supports(AdDestination $destination, string $event): bool;

    /**
     * Arma el cuerpo de la petición (sin credenciales: se leen al enviar).
     *
     * @return array<string, mixed>
     */
    public function build(ConversionInput $input, AdDestination $destination): array;

    /**
     * Claves de coincidencia presentes en el payload (calidad de la señal).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function matchKeys(array $payload): array;

    /** @param array<string, mixed> $payload */
    public function send(AdDestination $destination, array $payload): DeliveryResult;

    /** Comprueba credenciales sin registrar conversiones reales. */
    public function test(AdDestination $destination): DeliveryResult;
}
