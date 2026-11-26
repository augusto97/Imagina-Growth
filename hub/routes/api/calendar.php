<?php

use App\Domain\Campaigns\Http\CalendarController;
use Illuminate\Support\Facades\Route;

/*
 * Calendario comercial del espacio de trabajo (rediseño v2 §11.5, modulos.md M10). Dentro de /api/w/{workspace}.
 */

Route::get('calendar', [CalendarController::class, 'index']);
Route::post('calendar/{key}/campaign', [CalendarController::class, 'campaign'])->where('key', '[a-z0-9_]+')->middleware('throttle:30,1');
