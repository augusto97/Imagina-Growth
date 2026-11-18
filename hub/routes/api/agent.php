<?php

use App\Domain\Intelligence\Ai\Agent\Http\AgentReviewController;
use App\Domain\Intelligence\Ai\Agent\Http\AgentSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Agente de ventas con IA (M11, inteligencia.md §6.7). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('agent/settings', [AgentSettingsController::class, 'show']);
Route::put('agent/settings', [AgentSettingsController::class, 'update']);
Route::post('agent/sandbox', [AgentSettingsController::class, 'sandbox'])->middleware('throttle:30,1');
Route::get('agent/review', [AgentReviewController::class, 'index']);
Route::get('agent/turns/{turn}', [AgentReviewController::class, 'show'])->where('turn', 'agt_[0-9A-Z]{26}');
Route::put('agent/turns/{turn}/review', [AgentReviewController::class, 'review'])->where('turn', 'agt_[0-9A-Z]{26}');
