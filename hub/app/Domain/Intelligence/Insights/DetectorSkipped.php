<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use RuntimeException;

/**
 * Un detector que no puede correr con los datos de este espacio de trabajo (p. ej.
 * #7 sin Web Vitals). No es un error: la pantalla muestra el motivo y sus
 * hallazgos abiertos no vencen por eso.
 */
final class DetectorSkipped extends RuntimeException {}
