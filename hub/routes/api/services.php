<?php

use App\Domain\Services\Http\ContactServicesController;
use App\Domain\Services\Http\ServicesResultsController;
use Illuminate\Support\Facades\Route;

/*
 * Resultados de un negocio sin tienda (rediseño v2 §11.2, modulos.md M18). Dentro de /api/w/{workspace}.
 */

Route::get('services/results', ServicesResultsController::class)->middleware('throttle:60,1');

// Lo de servicios de una persona (leads, citas y cotizaciones) para su perfil y la ficha del lead (M03, M18).
Route::get('services/contacts/{contact}', ContactServicesController::class)->where('contact', 'ct_[0-9A-Z]{26}');
