<?php

use App\Domain\Measurement\Http\Controllers\DestinationController;
use App\Domain\Measurement\Http\Controllers\MeasurementReportController;
use Illuminate\Support\Facades\Route;

/*
 * Medición (M06): destinos de conversiones, entregas, calidad de la señal y gasto.
 * Se incluye dentro de /api/w/{workspace} (ver routes/api.php).
 */
Route::prefix('measurement')->group(function (): void {
    Route::get('destinations', [DestinationController::class, 'index']);
    Route::post('destinations', [DestinationController::class, 'store']);
    Route::patch('destinations/{destination}', [DestinationController::class, 'update']);
    Route::delete('destinations/{destination}', [DestinationController::class, 'destroy']);
    Route::post('destinations/{destination}/test', [DestinationController::class, 'test']);
    Route::get('deliveries', [MeasurementReportController::class, 'deliveries']);
    Route::get('quality', [MeasurementReportController::class, 'quality']);
    Route::get('spend', [MeasurementReportController::class, 'spend']);
});
