<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Data;

use App\Domain\Contacts\Models\Contact;

/**
 * Resultado de CaptureContact: el contacto (nuevo o el que ya existía por correo,
 * teléfono o navegador), si ya era conocido antes de esta captura y los canales
 * cuyo consentimiento quedó registrado (o pendiente de doble opt-in).
 *
 * @phpstan-type Granted list<string>
 */
final class CaptureResult
{
    /**
     * @param  list<string>  $granted
     * @param  list<string>  $pendingDoubleOptIn
     */
    public function __construct(
        public readonly Contact $contact,
        public readonly bool $wasKnown,
        public readonly array $granted,
        public readonly array $pendingDoubleOptIn = [],
    ) {}
}
