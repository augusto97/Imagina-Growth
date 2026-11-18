<?php

use Illuminate\Support\Facades\Route;

// SPA: cualquier ruta que no sea de la API, la autenticación, la ingesta o Horizon.
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|auth|v1|internal|health|sanctum|horizon|up|build).*$');
