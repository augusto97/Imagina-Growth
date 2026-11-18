<?php

use App\Domain\Quotes\Http\QuoteController;
use Illuminate\Support\Facades\Route;

/*
 * Cotizador (modulos.md M18). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('quotes/calculators', [QuoteController::class, 'index']);
Route::post('quotes/calculators', [QuoteController::class, 'save']);
Route::get('quotes/calculators/{calculator}', [QuoteController::class, 'show']);
Route::patch('quotes/calculators/{calculator}', [QuoteController::class, 'save']);
Route::delete('quotes/calculators/{calculator}', [QuoteController::class, 'destroy']);
Route::post('quotes/calculators/{calculator}/preview', [QuoteController::class, 'preview']);
Route::get('quotes/requests', [QuoteController::class, 'requests']);
