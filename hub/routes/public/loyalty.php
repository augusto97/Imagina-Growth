<?php

use App\Domain\Loyalty\Http\Public\MeController;
use App\Domain\Loyalty\Http\Public\WalletRedeemController;
use App\Http\Middleware\VerifySiteSignature;
use Illuminate\Support\Facades\Route;

/*
 * M13 en la tienda (api-connector.md §5.5 y §8.3). Sin sesión ni CSRF: el
 * canje de saldo va firmado por el sitio; los demás identifican al sitio por su
 * clave pública y a la persona por su igrUser.token. El OPTIONS de /p/{key}/…
 * lo responde PublicWidgetsController (routes/ingest.php).
 */

Route::post('v1/s/wallet/redeem', WalletRedeemController::class)->middleware(VerifySiteSignature::class);

Route::get('p/{publicKey}/me', [MeController::class, 'show']);
Route::post('p/{publicKey}/me/redeem', [MeController::class, 'redeem']);
Route::get('p/{publicKey}/points', [MeController::class, 'points'])->middleware('throttle:240,1');
Route::get('p/{publicKey}/program', [MeController::class, 'program'])->middleware('throttle:120,1');
