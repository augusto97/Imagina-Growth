<?php

declare(strict_types=1);

namespace App\Domain\Templates\Exceptions;

use RuntimeException;

/** La plantilla no se pudo renderizar (sintaxis, variable inexistente, tiempo agotado). */
final class TemplateRenderException extends RuntimeException {}
