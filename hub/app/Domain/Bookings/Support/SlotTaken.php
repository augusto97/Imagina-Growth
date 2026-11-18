<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use RuntimeException;

/** El horario ya no está disponible (otra persona lo tomó, o salió de la disponibilidad). */
final class SlotTaken extends RuntimeException {}
