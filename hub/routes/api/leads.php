<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Services\Leads\Http\LeadController;
use App\Domain\Services\Leads\Http\LeadKpiController;
use App\Domain\Services\Leads\Http\LeadSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Leads (M18). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('leads', [LeadController::class, 'index']);
Route::post('leads', [LeadController::class, 'store'])->middleware(['throttle:60,1', RequirePlanModule::class.':services']);
Route::get('leads/board', [LeadController::class, 'board']);
Route::get('leads/kpis', LeadKpiController::class);
Route::get('leads/team', [LeadController::class, 'team']);
Route::get('leads/settings', [LeadSettingsController::class, 'show']);
Route::put('leads/settings', [LeadSettingsController::class, 'update']);
Route::post('leads/sellers', [LeadSettingsController::class, 'storeSeller']);
Route::put('leads/sellers/{seller}', [LeadSettingsController::class, 'updateSeller'])->where('seller', 'lsl_[0-9A-Z]{26}');
Route::delete('leads/sellers/{seller}', [LeadSettingsController::class, 'destroySeller'])->where('seller', 'lsl_[0-9A-Z]{26}');
Route::post('leads/pipelines', [LeadSettingsController::class, 'storePipeline']);
Route::put('leads/pipelines/{pipeline}', [LeadSettingsController::class, 'updatePipeline'])->where('pipeline', 'lpl_[0-9A-Z]{26}');
Route::delete('leads/pipelines/{pipeline}', [LeadSettingsController::class, 'destroyPipeline'])->where('pipeline', 'lpl_[0-9A-Z]{26}');
Route::get('leads/{lead}', [LeadController::class, 'show'])->where('lead', 'ld_[0-9A-Z]{26}');
Route::patch('leads/{lead}', [LeadController::class, 'update'])->where('lead', 'ld_[0-9A-Z]{26}');
Route::post('leads/{lead}/stage', [LeadController::class, 'stage'])->where('lead', 'ld_[0-9A-Z]{26}');
Route::post('leads/{lead}/notes', [LeadController::class, 'note'])->where('lead', 'ld_[0-9A-Z]{26}')->middleware('throttle:60,1');
