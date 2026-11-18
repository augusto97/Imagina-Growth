<?php

use App\Domain\Reviews\Http\ReviewController;
use App\Domain\Reviews\Http\ReviewImportController;
use App\Domain\Reviews\Http\ReviewSettingsController;
use App\Domain\Surveys\Http\NpsController;
use Illuminate\Support\Facades\Route;

/*
 * Reseñas, NPS y CSAT (modulos.md M15). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('reviews', [ReviewController::class, 'index']);
Route::get('reviews/summary', [ReviewController::class, 'summary']);
Route::get('reviews/requests', [ReviewController::class, 'requests']);
Route::get('reviews/settings', [ReviewSettingsController::class, 'show']);
Route::put('reviews/settings', [ReviewSettingsController::class, 'update']);
Route::post('reviews/imports', ReviewImportController::class)->middleware('throttle:10,1');
Route::post('reviews/bulk', [ReviewController::class, 'bulk']);
Route::get('reviews/surveys/{kind}', [NpsController::class, 'show'])->whereIn('kind', ['nps', 'csat']);
Route::put('reviews/surveys/{kind}', [NpsController::class, 'update'])->whereIn('kind', ['nps', 'csat']);
Route::get('reviews/surveys/{kind}/report', [NpsController::class, 'report'])->whereIn('kind', ['nps', 'csat']);
Route::get('reviews/{review}', [ReviewController::class, 'show'])->where('review', 'rv_[0-9A-Z]{26}');
Route::post('reviews/{review}/approve', [ReviewController::class, 'approve'])->where('review', 'rv_[0-9A-Z]{26}');
Route::post('reviews/{review}/reject', [ReviewController::class, 'reject'])->where('review', 'rv_[0-9A-Z]{26}');
Route::put('reviews/{review}/reply', [ReviewController::class, 'reply'])->where('review', 'rv_[0-9A-Z]{26}');
