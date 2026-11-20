<?php

use App\Domain\Connections\Http\OAuthCallbackController;
use Illuminate\Support\Facades\Route;

// Enlace del correo «olvidé mi contraseña»: Fortify no registra vistas, así que la
// ruta con nombre password.reset la atiende la SPA (/restablecer/{token}?email=…).
Route::get('/restablecer/{token}', fn () => view('app'))->name('password.reset');

// Vuelta del consentimiento de Meta, Google y TikTok (Conexiones con un clic): con la sesión, para amarrar el state
// a la persona que empezó. Es la URL de retorno que se registra en cada app ({APP_URL}/oauth/{proveedor}/callback).
Route::get('/oauth/{provider}/callback', OAuthCallbackController::class)->whereIn('provider', ['meta', 'google', 'tiktok'])->middleware('throttle:30,1');

// SPA: cualquier ruta que no sea de la API, la autenticación, la ingesta, Horizon
// o los endpoints públicos de routes/public (c/ enlaces, u/ bajas, r/ reseñas, creadores, preferencias, webhooks).
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|auth|v1|p/|c/|u/|r/|oauth/|creadores|preferencias|webhooks|internal|health|sanctum|horizon|up|build).*$');
