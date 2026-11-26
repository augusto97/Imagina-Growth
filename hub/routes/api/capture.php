<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Capture\Http\LandingPageController;
use App\Domain\Capture\Http\LeadAdsController;
use App\Domain\Capture\Http\WhatsAppLinkController;
use Illuminate\Support\Facades\Route;

/*
 * Captación sin tienda (rediseño v2 §11.3): páginas de captura y QR (modulos.md M12), chats de WhatsApp con
 * atribución y leads de anuncios (M18). Dentro de /api/w/{workspace} (routes/api.php). Lo público va en routes/public/capture.php. Crear y
 * publicar exigen el módulo `onsite` del plan; leer lo que ya existe sigue abierto.
 */

$onsite = RequirePlanModule::class.':onsite';

Route::get('capture/options', [LandingPageController::class, 'options']);
Route::get('capture/pages', [LandingPageController::class, 'index']);
Route::post('capture/pages', [LandingPageController::class, 'store'])->middleware(['throttle:30,1', $onsite]);
Route::get('capture/pages/{page}', [LandingPageController::class, 'show']);
Route::patch('capture/pages/{page}', [LandingPageController::class, 'update']);
Route::delete('capture/pages/{page}', [LandingPageController::class, 'destroy']);
Route::post('capture/pages/{page}/preview', [LandingPageController::class, 'preview'])->middleware('throttle:120,1');
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

// Leads de anuncios (modulos.md M18): Meta Lead Ads y formularios de clientes potenciales de Google Ads. Conectar
// exige el módulo `services` (leads) del plan.
$services = RequirePlanModule::class.':services';
Route::get('capture/lead-ads', [LeadAdsController::class, 'index']);
Route::get('capture/lead-ads/meta/pages', [LeadAdsController::class, 'metaPages'])->middleware('throttle:30,1');
Route::post('capture/lead-ads/meta/pages', [LeadAdsController::class, 'subscribeMeta'])->middleware(['throttle:20,1', $services]);
Route::delete('capture/lead-ads/meta/pages/{source}', [LeadAdsController::class, 'unsubscribeMeta']);
Route::get('capture/lead-ads/sources/{source}/forms', [LeadAdsController::class, 'forms'])->middleware('throttle:30,1');
Route::put('capture/lead-ads/sources/{source}/forms/{form}', [LeadAdsController::class, 'saveForm'])->middleware('throttle:30,1');
Route::post('capture/lead-ads/google', [LeadAdsController::class, 'google'])->middleware(['throttle:10,1', $services]);
Route::delete('capture/lead-ads/google', [LeadAdsController::class, 'disableGoogle']);
Route::get('capture/lead-ads/leads', [LeadAdsController::class, 'leads']);
Route::post('capture/lead-ads/leads/{lead}/retry', [LeadAdsController::class, 'retry'])->middleware('throttle:30,1');
