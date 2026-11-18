<?php

declare(strict_types=1);

use App\Domain\Agency\Http\AgencyBrandingController;
use App\Domain\Agency\Http\AgencyController;
use App\Domain\Agency\Http\AgencyTemplateController;
use App\Domain\Agency\Http\MonthlyReportController;
use Illuminate\Support\Facades\Route;

/*
 * M22 Capa de agencia. `agency/*` cruza espacios de trabajo, pero solo los de
 * las cuentas de agencia de la persona (account_user); `reports*` es la vista
 * del cliente de sus propios reportes mensuales.
 */
Route::get('agency/overview', [AgencyController::class, 'overview']);
Route::post('agency/clients/{client}/enter', [AgencyController::class, 'enter']);
Route::get('agency/clients/{client}/items', [AgencyController::class, 'items']);

Route::get('agency/templates', [AgencyTemplateController::class, 'index']);
Route::post('agency/templates', [AgencyTemplateController::class, 'store']);
Route::get('agency/templates/{template}', [AgencyTemplateController::class, 'show']);
Route::patch('agency/templates/{template}', [AgencyTemplateController::class, 'update']);
Route::delete('agency/templates/{template}', [AgencyTemplateController::class, 'destroy']);
Route::post('agency/templates/{template}/apply', [AgencyTemplateController::class, 'apply']);
Route::post('agency/copy', [AgencyTemplateController::class, 'copy']);

Route::get('agency/reports', [MonthlyReportController::class, 'agencyIndex']);
Route::post('agency/reports/{client}/generate', [MonthlyReportController::class, 'agencyGenerate'])->middleware('throttle:30,1');
Route::post('agency/reports/{client}/{report}/send', [MonthlyReportController::class, 'agencySend'])->middleware('throttle:30,1');
Route::get('agency/reports/{client}/{report}/pdf', [MonthlyReportController::class, 'agencyPdf']);

Route::get('agency/branding', [AgencyBrandingController::class, 'index']);
Route::put('agency/branding/{account}', [AgencyBrandingController::class, 'update']);
Route::post('agency/branding/{account}/logo', [AgencyBrandingController::class, 'logo']);
Route::delete('agency/branding/{account}/logo', [AgencyBrandingController::class, 'deleteLogo']);
Route::post('agency/domains', [AgencyBrandingController::class, 'addDomain']);
Route::post('agency/domains/{domain}/verify', [AgencyBrandingController::class, 'verifyDomain'])->middleware('throttle:20,1');
Route::delete('agency/domains/{domain}', [AgencyBrandingController::class, 'deleteDomain']);

Route::get('reports', [MonthlyReportController::class, 'index']);
Route::post('reports/generate', [MonthlyReportController::class, 'generate'])->middleware('throttle:20,1');
Route::get('reports/{report}', [MonthlyReportController::class, 'show']);
Route::get('reports/{report}/pdf', [MonthlyReportController::class, 'pdf']);
Route::post('reports/{report}/send', [MonthlyReportController::class, 'send'])->middleware('throttle:20,1');
