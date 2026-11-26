<?php

use App\Domain\Services\Http\ServicesResultsController;
use Illuminate\Support\Facades\Route;

/*
 * Resultados de un negocio sin tienda (rediseño v2 §11.2, modulos.md M18). Dentro de /api/w/{workspace}.
 */

Route::get('services/results', ServicesResultsController::class)->middleware('throttle:60,1');
