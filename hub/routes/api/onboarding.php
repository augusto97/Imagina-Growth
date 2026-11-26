<?php

use App\Domain\Home\Http\HomeController;
use App\Domain\Onboarding\Http\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
 * El Inicio (docs/diseno/rediseno-v2.md §5) y el asistente de configuración (§4). Dentro de /api/w/{workspace}
 * (routes/api.php).
 */

Route::get('home', HomeController::class);

Route::get('onboarding', [OnboardingController::class, 'show']);
Route::put('onboarding', [OnboardingController::class, 'update']);
Route::post('onboarding/strategies', [OnboardingController::class, 'strategies'])->middleware('throttle:20,1');
