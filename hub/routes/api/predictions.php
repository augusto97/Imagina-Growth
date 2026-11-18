<?php

use App\Domain\Intelligence\Predictions\Http\PredictionController;
use Illuminate\Support\Facades\Route;

/*
 * Predicciones (M17, inteligencia.md §4). Se incluye dentro de /api/w/{workspace}
 * (ver routes/api.php).
 */
Route::get('predictions', [PredictionController::class, 'index']);
Route::post('predictions/run', [PredictionController::class, 'run'])->middleware('throttle:6,1');
Route::post('predictions/segments', [PredictionController::class, 'segment']);
