<?php

use App\Analytics\Http\AnalyticsController;
use Illuminate\Support\Facades\Route;

// Tableros de M05 y capa semántica (incluido en el grupo /api/w/{workspace} de routes/api.php).
Route::get('analytics/metrics', [AnalyticsController::class, 'metrics']);
Route::get('analytics/query', [AnalyticsController::class, 'query']);
Route::get('analytics/{dashboard}', [AnalyticsController::class, 'dashboard'])->where('dashboard', '[a-z_]+');
