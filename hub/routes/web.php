<?php

use Illuminate\Support\Facades\Route;

// Enlace del correo «olvidé mi contraseña»: Fortify no registra vistas, así que la
// ruta con nombre password.reset la atiende la SPA (/restablecer/{token}?email=…).
Route::get('/restablecer/{token}', fn () => view('app'))->name('password.reset');

// SPA: cualquier ruta que no sea de la API, la autenticación, la ingesta, Horizon
// o los endpoints públicos de routes/public (c/ enlaces, u/ bajas, preferencias, webhooks).
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|auth|v1|p/|c/|u/|r/|preferencias|webhooks|internal|health|sanctum|horizon|up|build).*$');
