<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Platforms;

use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;

/**
 * Una plataforma de audiencias (M06, Fase 3). Los miembros van solo con
 * identificadores con hash SHA-256 (eventos.md §7), normalizados según la
 * plataforma; nunca el correo o el teléfono en claro.
 */
interface AudiencePlatform
{
    public function platform(): Platform;

    /** null si el destino tiene lo necesario; si no, qué falta, en un mensaje humano. */
    public function missing(AdDestination $destination): ?string;

    /**
     * Crea la audiencia en la plataforma.
     *
     * @return array{0: DeliveryResult, 1: string|null} resultado e ID de la audiencia
     */
    public function create(AdDestination $destination, AudienceSync $sync): array;

    /** @param list<array<string, string>> $members identificadores por miembro */
    public function add(AdDestination $destination, string $audienceId, array $members): DeliveryResult;

    /** @param list<array<string, string>> $members */
    public function remove(AdDestination $destination, string $audienceId, array $members): DeliveryResult;

    /**
     * Identificadores con hash de una persona para esta plataforma (vacío si no tiene correo ni teléfono).
     *
     * @return array<string, string>
     */
    public function identifiers(?string $email, ?string $phone, string $defaultCountry): array;

    /**
     * Miembros por petición.
     *
     * @return positive-int
     */
    public function batchSize(): int;
}
