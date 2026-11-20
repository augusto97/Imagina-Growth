<?php

use App\Domain\Connections\Http\ConnectionController;
use App\Domain\Connections\Http\WhatsAppSignupController;
use Illuminate\Support\Facades\Route;

/*
 * Conexiones con un clic (docs/auditoria-2026-11.md §6). Dentro de /api/w/{workspace} (routes/api.php).
 * La vuelta del consentimiento es GET /oauth/{proveedor}/callback, en routes/web.php (necesita la sesión).
 */

Route::get('connections', [ConnectionController::class, 'index']);
Route::get('connections/alerts', [ConnectionController::class, 'alerts']);
Route::post('connections/meta_whatsapp/start', [WhatsAppSignupController::class, 'start'])->middleware('throttle:20,1');
Route::post('connections/meta_whatsapp/complete', [WhatsAppSignupController::class, 'complete'])->middleware('throttle:20,1');
Route::post('connections/meta_whatsapp/abandon', [WhatsAppSignupController::class, 'abandon'])->middleware('throttle:20,1');
Route::post('connections/{provider}/start', [ConnectionController::class, 'start'])->whereIn('provider', ['meta', 'google', 'tiktok'])->middleware('throttle:20,1');

Route::prefix('connections/{connection}')->where(['connection' => 'oac_[0-9A-Z]{26}'])->group(function (): void {
    Route::get('assets', [ConnectionController::class, 'assets'])->middleware('throttle:60,1');
    Route::post('measurement', [ConnectionController::class, 'measurement'])->middleware('throttle:20,1');
    Route::post('social', [ConnectionController::class, 'social'])->middleware('throttle:20,1');
    Route::post('whatsapp-number', [WhatsAppSignupController::class, 'number'])->middleware('throttle:20,1');
    Route::post('check', [ConnectionController::class, 'check'])->middleware('throttle:10,1');
    Route::delete('/', [ConnectionController::class, 'destroy']);
});
