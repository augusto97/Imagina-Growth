<?php

use App\Domain\Reviews\Http\Public\PublicReviewsController;
use App\Domain\Reviews\Http\Public\ReviewFormController;
use App\Domain\Reviews\Http\Public\ReviewMediaController;
use App\Domain\Surveys\Http\NpsPageController;
use Illuminate\Support\Facades\Route;

/*
 * M15: reseñas publicadas para los widgets (api-connector.md §8.3), el formulario
 * de reseña de 1 toque (/r/{token}), la encuesta NPS o CSAT (/r/nps/{token}) y
 * los medios de las reseñas (/r/m/…). Sin sesión ni CSRF: los protege el token
 * firmado o son públicos.
 */

Route::get('p/{publicKey}/reviews', [PublicReviewsController::class, 'index'])->middleware('throttle:240,1');
Route::get('p/{publicKey}/reviews/badges', [PublicReviewsController::class, 'badges'])->middleware('throttle:240,1');

Route::get('r/m/{review}/{name}', ReviewMediaController::class)->name('reviews.media')->middleware('throttle:600,1')
    ->where(['review' => '[0-9A-HJKMNP-TV-Z]{26}', 'name' => '[a-z0-9]{32}(_t)?\.(jpg|mp4|mov)']);

Route::get('r/nps/{token}', [NpsPageController::class, 'show'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');
Route::post('r/nps/{token}', [NpsPageController::class, 'store'])->middleware('throttle:20,1')->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');

Route::get('r/{token}', [ReviewFormController::class, 'show'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');
Route::post('r/{token}', [ReviewFormController::class, 'store'])->middleware('throttle:20,1')->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');
Route::get('r/{token}/gracias', [ReviewFormController::class, 'thanks'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');
