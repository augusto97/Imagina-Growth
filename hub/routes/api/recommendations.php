<?php

use App\Domain\Recommendations\Http\Api\BannersController;
use App\Domain\Recommendations\Http\Api\RecommendationsController;
use App\Domain\Recommendations\Http\Api\WatchesController;
use Illuminate\Support\Facades\Route;

/*
 * Comercio en el sitio (M16): recomendaciones, «avísame» y banners por segmento.
 * Se incluye dentro de /api/w/{workspace} (ver routes/api.php).
 */
Route::prefix('onsite-commerce')->group(function (): void {
    Route::get('recommendations', [RecommendationsController::class, 'overview']);
    Route::patch('recommendations/settings', [RecommendationsController::class, 'updateSettings']);
    Route::get('recommendations/preview', [RecommendationsController::class, 'preview']);
    Route::get('recommendations/associations', [RecommendationsController::class, 'associations']);
    Route::post('recommendations/rebuild', [RecommendationsController::class, 'rebuild']);
    Route::get('products', [RecommendationsController::class, 'products']);

    Route::get('watches', [WatchesController::class, 'index']);
    Route::get('watches/summary', [WatchesController::class, 'summary']);
    Route::patch('watches/settings', [WatchesController::class, 'updateSettings']);
    Route::post('watches/{watch}/cancel', [WatchesController::class, 'cancel']);

    Route::get('banners', [BannersController::class, 'index']);
    Route::post('banners', [BannersController::class, 'store']);
    Route::patch('banners/{banner}', [BannersController::class, 'update']);
    Route::delete('banners/{banner}', [BannersController::class, 'destroy']);
});
