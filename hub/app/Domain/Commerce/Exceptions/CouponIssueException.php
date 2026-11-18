<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

/** El sitio no pudo crear el cupón. */
final class CouponIssueException extends RuntimeException {}
