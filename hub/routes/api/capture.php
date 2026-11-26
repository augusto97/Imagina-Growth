<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Capture\Http\LandingPageController;
use App\Domain\Capture\Http\WhatsAppLinkController;
use Illuminate\Support\Facades\Route;

/*
 * Captación sin tienda (rediseño v2 §11.3): páginas de captura y QR (modulos.md M12) y chats de WhatsApp con
 * atribución. Dentro de /api/w/{workspace} (routes/api.php). Lo público va en routes/public/capture.php. Crear y
 * publicar exigen el módulo `onsite` del plan; leer lo que ya existe sigue abierto.
 */

$onsite = RequirePlanModule::class.':onsite';

Route::get('capture/options', [LandingPageController::class, 'options']);
Route::get('capture/pages', [LandingPageController::class, 'index']);
Route::post('capture/pages', [LandingPageController::class, 'store'])->middleware(['throttle:30,1', $onsite]);
Route::get('capture/pages/{page}', [LandingPageController::class, 'show']);
Route::patch('capture/pages/{page}', [LandingPageController::class, 'update']);
Route::delete('capture/pages/{page}', [LandingPageController::class, 'destroy']);
Route::post('capture/pages/{page}/publish', [LandingPageController::class, 'publish'])->middleware($onsite);
Route::post('capture/pages/{page}/unpublish', [LandingPageController::class, 'unpublish']);
Route::post('capture/pages/{page}/images', [LandingPageController::class, 'uploadImage'])->middleware('throttle:30,1');
Route::delete('capture/pages/{page}/images/{kind}', [LandingPageController::class, 'deleteImage']);
Route::get('capture/pages/{page}/stats', [LandingPageController::class, 'stats']);
Route::get('capture/pages/{page}/submissions', [LandingPageController::class, 'submissions']);
Route::get('capture/pages/{page}/qr', [LandingPageController::class, 'qr'])->middleware('throttle:60,1');

Route::get('capture/whatsapp-links', [WhatsAppLinkController::class, 'index']);
Route::post('capture/whatsapp-links', [WhatsAppLinkController::class, 'store'])->middleware(['throttle:30,1', $onsite]);
Route::get('capture/whatsapp-links/{link}', [WhatsAppLinkController::class, 'show']);
Route::patch('capture/whatsapp-links/{link}', [WhatsAppLinkController::class, 'update']);
Route::delete('capture/whatsapp-links/{link}', [WhatsAppLinkController::class, 'destroy']);
Route::get('capture/whatsapp-links/{link}/stats', [WhatsAppLinkController::class, 'stats']);
Route::get('capture/whatsapp-links/{link}/qr', [WhatsAppLinkController::class, 'qr'])->middleware('throttle:60,1');
