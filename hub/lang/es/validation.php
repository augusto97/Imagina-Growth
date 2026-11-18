<?php

/*
 * Mensajes de validación en español neutro. Las reglas que no estén aquí caen
 * al inglés (fallback_locale).
 */
return [
    'accepted' => 'Debes aceptar :attribute.',
    'array' => ':Attribute debe ser una lista.',
    'boolean' => ':Attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => ':Attribute no es una fecha válida.',
    'date_format' => ':Attribute debe tener el formato :format.',
    'email' => ':Attribute debe ser un correo válido.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no existe.',
    'in' => 'El valor de :attribute no es válido.',
    'integer' => ':Attribute debe ser un número entero.',
    'json' => ':Attribute debe ser JSON válido.',
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'array' => ':Attribute debe tener al menos :min elementos.',
        'numeric' => ':Attribute debe ser al menos :min.',
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'numeric' => ':Attribute debe ser un número.',
    'password' => [
        'letters' => ':Attribute debe tener al menos una letra.',
        'mixed' => ':Attribute debe tener mayúsculas y minúsculas.',
        'numbers' => ':Attribute debe tener al menos un número.',
        'symbols' => ':Attribute debe tener al menos un símbolo.',
        'uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
    ],
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':Attribute es obligatorio.',
    'required_with' => ':Attribute es obligatorio cuando hay :values.',
    'size' => [
        'string' => ':Attribute debe tener :size caracteres.',
    ],
    'string' => ':Attribute debe ser texto.',
    'timezone' => ':Attribute debe ser una zona horaria válida.',
    'unique' => 'Ya existe una cuenta o registro con ese :attribute.',
    'url' => ':Attribute debe ser una URL válida.',

    'attributes' => [
        'name' => 'el nombre',
        'email' => 'el correo',
        'password' => 'la contraseña',
        'role' => 'el rol',
        'country' => 'el país',
        'timezone' => 'la zona horaria',
        'currency' => 'la moneda',
        'locale' => 'el idioma',
        'industry' => 'la industria',
        'site_url' => 'la URL del sitio',
        'return_url' => 'la URL de regreso',
        'code' => 'el código',
        'legal_name' => 'la razón social',
        'privacy_policy_url' => 'la URL de la política de privacidad',
    ],
];
