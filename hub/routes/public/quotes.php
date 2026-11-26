<?php

use App\Domain\Quotes\Http\Public\PublicQuoteController;
use App\Domain\Quotes\Http\Public\QuotePageController;
use Illuminate\Support\Facades\Route;

/*
 * El cotizador en la tienda (M18, api-connector.md §8.3). Sin firma: el sitio por
 * su clave pública, CORS a sus dominios, límites por IP con hash y por
 * anonymous_id. El OPTIONS lo responde PublicWidgetsController (routes/ingest.php).
 */

Route::get('p/{publicKey}/quotes/{calculator}', [PublicQuoteController::class, 'show'])->where('calculator', 'qc_[0-9A-Za-z]{26}');
Route::post('p/{publicKey}/quotes/{calculator}/estimate', [PublicQuoteController::class, 'estimate'])->where('calculator', 'qc_[0-9A-Za-z]{26}');

/*
 * La página del cotizador en el hub (M18), para compartir por enlace o QR sin WordPress: la clave es la pública del
 * sitio (pk_…) o el ID público del espacio de trabajo (ws_…). Sin sesión ni CSRF: campo trampa y límites por IP con
 * hash en el controlador; el resultado va con un enlace firmado.
 */
Route::get('p/{publicKey}/cotizar/{calculator}', [QuotePageController::class, 'show'])->where('calculator', 'qc_[0-9A-Za-z]{26}');
Route::post('p/{publicKey}/cotizar/{calculator}', [QuotePageController::class, 'submit'])->where('calculator', 'qc_[0-9A-Za-z]{26}');
Route::get('p/{publicKey}/cotizar/{calculator}/r/{quote}/{signature}', [QuotePageController::class, 'result'])
    ->where(['calculator' => 'qc_[0-9A-Za-z]{26}', 'quote' => 'qt_[0-9A-Za-z]{26}', 'signature' => '[0-9a-f]{32}']);
