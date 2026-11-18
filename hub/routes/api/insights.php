<?php

use App\Domain\Intelligence\Http\InsightController;
use Illuminate\Support\Facades\Route;

// Hallazgos (inteligencia.md §5).
Route::get('insights', [InsightController::class, 'index']);
Route::get('insights/{insight}', [InsightController::class, 'show']);
Route::patch('insights/{insight}', [InsightController::class, 'update']);
