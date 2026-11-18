<?php

declare(strict_types=1);

namespace App\Domain\Segments\Definition;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/** La definición del segmento no es válida. Lleva los errores por ruta del AST. */
final class InvalidDefinition extends RuntimeException
{
    /** @param array<string, string> $errors ruta => mensaje */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (reset($errors) ?: __('La definición del segmento no es válida.')));
    }

    public function toValidationException(string $field = 'definition'): ValidationException
    {
        $messages = [];
        foreach ($this->errors as $path => $message) {
            $messages[$path === '' ? $field : $field.'.'.$path] = [$message];
        }

        return ValidationException::withMessages($messages);
    }
}
