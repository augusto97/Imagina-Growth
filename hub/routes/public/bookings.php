<?php

use App\Domain\Bookings\Http\Public\BookingPageController;
use App\Domain\Bookings\Http\Public\GoogleCallbackController;
use Illuminate\Support\Facades\Route;

/*
 * Página pública de reservas (M18, api-connector.md §8.3). Sin sesión ni CSRF:
 * la clave es la pública del sitio (pk_…) o el ID público del espacio de trabajo
 * (ws_…); la gestión de una cita la protege su token de 40 caracteres. Límites
 * por IP (con hash) en el controlador. Van bajo /p/ porque la SPA atiende el
 * resto de rutas (routes/web.php).
 */

Route::get('p/_google/calendar/callback', GoogleCallbackController::class);

Route::get('p/{publicKey}/reservar', [BookingPageController::class, 'index']);
Route::get('p/{publicKey}/reservar/{slug}', [BookingPageController::class, 'show'])->where('slug', '[a-z0-9-]{1,80}');
Route::post('p/{publicKey}/reservar/{slug}', [BookingPageController::class, 'store'])->where('slug', '[a-z0-9-]{1,80}');
Route::get('p/{publicKey}/cita/{token}', [BookingPageController::class, 'manage']);
Route::get('p/{publicKey}/cita/{token}/calendario.ics', [BookingPageController::class, 'ics']);
Route::post('p/{publicKey}/cita/{token}/{action}', [BookingPageController::class, 'act'])->whereIn('action', ['confirmar', 'reprogramar', 'cancelar']);
