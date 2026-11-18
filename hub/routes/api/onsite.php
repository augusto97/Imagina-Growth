<?php

use App\Domain\Onsite\Http\WidgetController;
use Illuminate\Support\Facades\Route;

/*
 * En el sitio: widgets de captación (modulos.md M12). Dentro de /api/w/{workspace}
 * (routes/api.php). Los endpoints públicos van en routes/public/onsite.php.
 */

Route::get('onsite/options', [WidgetController::class, 'options']);
Route::get('onsite/widgets', [WidgetController::class, 'index']);
Route::post('onsite/widgets', [WidgetController::class, 'store']);
Route::get('onsite/widgets/{widget}', [WidgetController::class, 'show']);
Route::patch('onsite/widgets/{widget}', [WidgetController::class, 'update']);
Route::delete('onsite/widgets/{widget}', [WidgetController::class, 'destroy']);
Route::post('onsite/widgets/{widget}/duplicate', [WidgetController::class, 'duplicate']);
Route::post('onsite/widgets/{widget}/activate', [WidgetController::class, 'activate']);
Route::post('onsite/widgets/{widget}/pause', [WidgetController::class, 'pause']);
Route::post('onsite/widgets/{widget}/archive', [WidgetController::class, 'archive']);
Route::get('onsite/widgets/{widget}/stats', [WidgetController::class, 'stats']);
Route::get('onsite/widgets/{widget}/captures', [WidgetController::class, 'captures']);
