<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use RuntimeException;

/** Archivo de una reseña rechazado (tipo, tamaño o contenido): el mensaje se muestra a la persona. */
final class InvalidReviewMedia extends RuntimeException {}
