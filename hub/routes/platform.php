<?php

use App\Domain\Billing\Http\PlatformController;
use Illuminate\Support\Facades\Route;

/*
 * Panel interno de la plataforma (hub/CLAUDE.md §16): /api/platform/*, solo
 * superadmin (EnsurePlatformAdmin). Lo carga App\Domain\Billing\ModuleServiceProvider.
 */
Route::get('overview', [PlatformController::class, 'overview']);
Route::get('health', [PlatformController::class, 'health']);
Route::put('accounts/{account}/plan', [PlatformController::class, 'assignPlan']);
Route::put('accounts/{account}/billing-status', [PlatformController::class, 'setBillingStatus']);
Route::get('accounts/{account}/invoice-draft', [PlatformController::class, 'invoiceDraft']);
Route::post('accounts/{account}/invoices', [PlatformController::class, 'createInvoice']);
Route::put('workspaces/{workspace}/features/{feature}', [PlatformController::class, 'setFeature']);
Route::post('invoices/{invoice}/{action}', [PlatformController::class, 'invoiceAction'])->whereIn('action', ['send', 'paid', 'void']);
Route::get('invoices/{invoice}/pdf', [PlatformController::class, 'invoicePdf']);
Route::post('plan-requests/{planRequest}/{action}', [PlatformController::class, 'planRequest'])->whereIn('action', ['apply', 'reject']);
