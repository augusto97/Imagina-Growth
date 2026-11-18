<?php

use App\Domain\Recommendations\Http\Public\CommercePublicController;
use Illuminate\Support\Facades\Route;

/*
 * M16, comercio en el sitio (api-connector.md §8.3): recomendaciones, «avísame»
 * y banner personalizado. Sin sesión ni CSRF; el preflight CORS de /p/{key}/*
 * lo atiende routes/ingest.php. Los límites por IP (con hash) y por navegador
 * van en el controlador.
 */
Route::get('p/{publicKey}/reco', [CommercePublicController::class, 'reco']);
Route::post('p/{publicKey}/watch', [CommercePublicController::class, 'watch']);
Route::get('p/{publicKey}/banner', [CommercePublicController::class, 'banner']);
