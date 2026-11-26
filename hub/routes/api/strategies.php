<?php

use App\Domain\Strategies\Http\StrategyController;
use Illuminate\Support\Facades\Route;

/*
 * Estrategias (docs/diseno/rediseno-v2.md §6). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('strategies', [StrategyController::class, 'index']);
Route::get('strategies/{key}', [StrategyController::class, 'show'])->where('key', '[a-z_]+');
Route::post('strategies/{key}/activate', [StrategyController::class, 'activate'])->where('key', '[a-z_]+')->middleware('throttle:60,1');
Route::post('strategies/{key}/pause', [StrategyController::class, 'pause'])->where('key', '[a-z_]+')->middleware('throttle:60,1');
