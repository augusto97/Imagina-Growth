<?php

use App\Domain\Messaging\Http\Public\PreferenceCenterController;
use App\Domain\Messaging\Http\Public\TrackedLinkController;
use App\Domain\Messaging\Http\Public\UnsubscribeController;
use App\Domain\Messaging\Http\Webhooks\MetaWebhookController;
use App\Domain\Messaging\Http\Webhooks\SesWebhookController;
use App\Domain\Messaging\Http\Webhooks\TwilioWebhookController;
use App\Domain\Messaging\Http\Webhooks\ZenderWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * M07: enlaces rastreados, baja de un clic (RFC 8058), centro de preferencias y
 * webhooks de los proveedores. Sin sesión ni CSRF: los protege el token firmado
 * o la firma del proveedor (hub/CLAUDE.md §8).
 */

Route::get('c/{code}', TrackedLinkController::class)->where('code', '[A-Za-z0-9]{8,16}')->middleware('throttle:240,1');
Route::get('u/{token}', [UnsubscribeController::class, 'show'])->middleware('throttle:60,1');
Route::post('u/{token}', [UnsubscribeController::class, 'store'])->middleware('throttle:60,1');
Route::get('preferencias/{token}', [PreferenceCenterController::class, 'show'])->middleware('throttle:60,1');
Route::post('preferencias/{token}', [PreferenceCenterController::class, 'update'])->middleware('throttle:60,1');

Route::get('webhooks/meta', [MetaWebhookController::class, 'verify']);
Route::post('webhooks/meta', [MetaWebhookController::class, 'handle']);
Route::post('webhooks/ses', SesWebhookController::class);
Route::post('webhooks/zender/{account}', ZenderWebhookController::class);
Route::post('webhooks/twilio/{account}', TwilioWebhookController::class);
