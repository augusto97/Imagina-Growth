<?php

use App\Domain\Intelligence\Ai\Features\Http\AiController;
use Illuminate\Support\Facades\Route;

/*
 * IA del panel (M17, Fase 4): analista semanal, «Pregúntale a tus datos» y
 * resúmenes. Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('ai/usage', [AiController::class, 'usage']);
Route::get('ai/reports', [AiController::class, 'reports']);
Route::post('ai/reports/generate', [AiController::class, 'generate']);
Route::get('ai/reports/{report}', [AiController::class, 'report'])->where('report', 'air_[0-9A-Z]{26}');
Route::post('ai/reports/{report}/send', [AiController::class, 'send'])->where('report', 'air_[0-9A-Z]{26}')->middleware('throttle:10,1');
Route::get('ai/ask', [AiController::class, 'history']);
Route::post('ai/ask', [AiController::class, 'ask']);
Route::get('ai/saved', [AiController::class, 'saved']);
Route::get('ai/ask/{query}/run', [AiController::class, 'run'])->where('query', 'adq_[0-9A-Z]{26}');
Route::post('ai/ask/{query}/save', [AiController::class, 'save'])->where('query', 'adq_[0-9A-Z]{26}');
Route::delete('ai/ask/{query}/saved', [AiController::class, 'unsave'])->where('query', 'adq_[0-9A-Z]{26}');
Route::get('ai/review-summary', [AiController::class, 'reviewSummary']);
Route::get('contacts/{contact}/ai-summary', [AiController::class, 'profileSummary'])->where('contact', 'ct_[0-9A-Z]{26}');
