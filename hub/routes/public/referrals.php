<?php

use App\Domain\Referrals\Http\Public\CreatorPortalController;
use App\Domain\Referrals\Http\Public\ReferralShareController;
use Illuminate\Support\Facades\Route;

/*
 * M14: compartir el enlace de referido desde «Mis recompensas» y el panel del
 * creador con enlace mágico (api-connector.md §8.3). Sin sesión ni CSRF: los
 * protegen el token del sitio y el token firmado del creador.
 */

Route::post('p/{publicKey}/me/share', ReferralShareController::class)->where('publicKey', 'pk_[0-9a-z]{32}');
Route::get('creadores/{token}', [CreatorPortalController::class, 'show'])->where('token', '[A-Za-z0-9_.-]+')->middleware('throttle:60,1');
Route::post('creadores/{token}', [CreatorPortalController::class, 'resend'])->where('token', '[A-Za-z0-9_.-]+')->middleware('throttle:10,1');
