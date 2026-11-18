<?php

use App\Domain\Api\Http\Spa\IntegrationsController;
use App\Domain\Api\Http\Spa\McpDraftController;
use App\Domain\Webhooks\Http\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * «API e integraciones» (M19): tokens, webhooks, servidor MCP y ecosistema Imagina.
 * Dentro de /api/w/{workspace} (routes/api.php). Permiso `manageApi` en el controlador.
 */

Route::get('integrations', [IntegrationsController::class, 'overview']);
Route::get('integrations/tokens', [IntegrationsController::class, 'tokens']);
Route::post('integrations/tokens', [IntegrationsController::class, 'createToken'])->middleware('throttle:20,1');
Route::delete('integrations/tokens/{client}', [IntegrationsController::class, 'revokeToken'])->where('client', 'apc_[0-9A-Z]{26}');
Route::post('integrations/ecosystem/{preset}', [IntegrationsController::class, 'connectPreset'])->where('preset', '[a-z]+')->middleware('throttle:20,1');

Route::get('integrations/mcp-drafts', [McpDraftController::class, 'index']);
Route::post('integrations/mcp-drafts/{draft}/approve', [McpDraftController::class, 'approve'])->where('draft', 'aid_[0-9A-Z]{26}');
Route::post('integrations/mcp-drafts/{draft}/discard', [McpDraftController::class, 'discard'])->where('draft', 'aid_[0-9A-Z]{26}');

Route::get('webhooks', [WebhookController::class, 'index']);
Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:20,1');
Route::patch('webhooks/{webhook}', [WebhookController::class, 'update'])->where('webhook', 'whk_[0-9A-Z]{26}');
Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->where('webhook', 'whk_[0-9A-Z]{26}');
Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotate'])->where('webhook', 'whk_[0-9A-Z]{26}');
Route::post('webhooks/{webhook}/ping', [WebhookController::class, 'ping'])->where('webhook', 'whk_[0-9A-Z]{26}')->middleware('throttle:10,1');
Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->where('webhook', 'whk_[0-9A-Z]{26}');
Route::post('webhook-deliveries/{delivery}/replay', [WebhookController::class, 'replay'])->where('delivery', 'whd_[0-9]+')->middleware('throttle:30,1');
