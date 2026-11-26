<?php

use App\Domain\Home\Http\HomeController;
use App\Domain\Home\Http\SectionController;
use App\Domain\Onboarding\Http\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
 * El Inicio (docs/diseno/rediseno-v2.md §5), las páginas de entrada de las secciones (§3) y el asistente de
 * configuración (§4). Dentro de /api/w/{workspace}
 * (routes/api.php).
 */

Route::get('home', HomeController::class);
// Las cifras y lo primero que conviene hacer en la página de entrada de cada sección (§3).
Route::get('sections/{section}', SectionController::class)->where('section', 'customers|conversations|messages|onsite|results|settings');

Route::get('onboarding', [OnboardingController::class, 'show']);
Route::put('onboarding', [OnboardingController::class, 'update']);
Route::post('onboarding/strategies', [OnboardingController::class, 'strategies'])->middleware('throttle:20,1');
