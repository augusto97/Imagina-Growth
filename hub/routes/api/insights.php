<?php

use App\Domain\Intelligence\Http\InsightController;
use Illuminate\Support\Facades\Route;

// Hallazgos (inteligencia.md §5).
Route::get('insights', [InsightController::class, 'index']);
Route::get('insights/settings', [InsightController::class, 'settings']);
Route::put('insights/assumptions/{key}', [InsightController::class, 'assumption'])->where('key', '[a-z_]+');
Route::get('insights/{insight}', [InsightController::class, 'show']);
Route::patch('insights/{insight}', [InsightController::class, 'update']);
Route::post('insights/{insight}/act', [InsightController::class, 'act']);
