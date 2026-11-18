<?php

use App\Domain\Quotes\Http\Public\PublicQuoteController;
use Illuminate\Support\Facades\Route;

/*
 * El cotizador en la tienda (M18, api-connector.md §8.3). Sin firma: el sitio por
 * su clave pública, CORS a sus dominios, límites por IP con hash y por
 * anonymous_id. El OPTIONS lo responde PublicWidgetsController (routes/ingest.php).
 */

Route::get('p/{publicKey}/quotes/{calculator}', [PublicQuoteController::class, 'show'])->where('calculator', 'qc_[0-9A-Za-z]{26}');
Route::post('p/{publicKey}/quotes/{calculator}/estimate', [PublicQuoteController::class, 'estimate'])->where('calculator', 'qc_[0-9A-Za-z]{26}');
