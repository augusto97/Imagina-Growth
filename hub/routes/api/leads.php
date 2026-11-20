<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Services\Leads\Http\LeadController;
use App\Domain\Services\Leads\Http\LeadFunnelController;
use App\Domain\Services\Leads\Http\LeadKpiController;
use App\Domain\Services\Leads\Http\LeadQualificationController;
use App\Domain\Services\Leads\Http\LeadSettingsController;
use App\Domain\Services\Leads\Http\LeadTaskController;
use App\Domain\Services\Leads\Http\TodayController;
use Illuminate\Support\Facades\Route;

/*
 * Leads (M18). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('leads', [LeadController::class, 'index']);
Route::post('leads', [LeadController::class, 'store'])->middleware(['throttle:60,1', RequirePlanModule::class.':services']);
Route::get('leads/board', [LeadController::class, 'board']);
Route::get('leads/kpis', LeadKpiController::class);
Route::get('leads/funnel', LeadFunnelController::class);
Route::get('leads/team', [LeadController::class, 'team']);
// «Hoy», el asistente del vendedor: lista del día, mensaje sugerido, enviar, hecho y posponer.
Route::get('leads/today', [TodayController::class, 'index']);
Route::post('leads/today/suggest', [TodayController::class, 'suggest'])->middleware('throttle:60,1');
Route::post('leads/today/send', [TodayController::class, 'send'])->middleware('throttle:60,1');
Route::post('leads/today/done', [TodayController::class, 'done']);
Route::post('leads/today/snooze', [TodayController::class, 'snooze']);
Route::patch('leads/tasks/{task}', [LeadTaskController::class, 'update'])->where('task', 'ltk_[0-9A-Z]{26}');
Route::get('leads/qualification', [LeadQualificationController::class, 'index']);
Route::post('leads/qualification', [LeadQualificationController::class, 'store']);
Route::put('leads/qualification/{set}', [LeadQualificationController::class, 'update'])->where('set', 'lqs_[0-9A-Z]{26}');
Route::delete('leads/qualification/{set}', [LeadQualificationController::class, 'destroy'])->where('set', 'lqs_[0-9A-Z]{26}');
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
Route::patch('leads/{lead}/brief', [LeadController::class, 'brief'])->where('lead', 'ld_[0-9A-Z]{26}');
Route::post('leads/{lead}/tasks', [LeadTaskController::class, 'store'])->where('lead', 'ld_[0-9A-Z]{26}')->middleware('throttle:60,1');
Route::post('leads/{lead}/notes', [LeadController::class, 'note'])->where('lead', 'ld_[0-9A-Z]{26}')->middleware('throttle:60,1');
