<?php

use App\Domain\Measurement\Audiences\Http\AudienceController;
use Illuminate\Support\Facades\Route;

/*
 * Audiencias hacia anuncios (M06, Fase 3): segmento → Meta, Google y TikTok.
 * Se incluye dentro de /api/w/{workspace} (ver routes/api.php).
 */
Route::prefix('measurement/audiences')->group(function (): void {
    Route::get('/', [AudienceController::class, 'index']);
    Route::post('/', [AudienceController::class, 'store']);
    Route::patch('{audience}', [AudienceController::class, 'update']);
    Route::post('{audience}/sync', [AudienceController::class, 'sync']);
    Route::delete('{audience}', [AudienceController::class, 'destroy']);
});
