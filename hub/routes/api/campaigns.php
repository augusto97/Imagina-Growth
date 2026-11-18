<?php

use App\Domain\Campaigns\Http\CampaignController;
use Illuminate\Support\Facades\Route;

/*
 * Campañas (modulos.md M10). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('campaigns', [CampaignController::class, 'index']);
Route::post('campaigns', [CampaignController::class, 'store']);
Route::get('campaigns/options', [CampaignController::class, 'options']);
Route::get('campaigns/calendar', [CampaignController::class, 'calendar']);
Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
Route::patch('campaigns/{campaign}', [CampaignController::class, 'update']);
Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);
Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate']);
Route::post('campaigns/{campaign}/audience-count', [CampaignController::class, 'audienceCount']);
Route::post('campaigns/{campaign}/review', [CampaignController::class, 'review']);
Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
Route::get('campaigns/{campaign}/report', [CampaignController::class, 'report']);
