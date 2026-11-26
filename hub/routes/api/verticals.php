<?php

use App\Domain\Services\Verticals\Http\VerticalController;
use Illuminate\Support\Facades\Route;

/*
 * Perfiles de vertical (modulos.md M18 «Verticales»). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('verticals', [VerticalController::class, 'index']);
Route::post('verticals/{key}/apply', [VerticalController::class, 'apply'])->where('key', '[a-z_]+');
