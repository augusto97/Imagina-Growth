<?php

use App\Domain\Billing\Http\PlatformClientsController;
use App\Domain\Billing\Http\PlatformController;
use App\Domain\Billing\Http\PlatformDoctorController;
use App\Domain\Billing\Http\PlatformExchangeRatesController;
use App\Domain\Billing\Http\PlatformMailController;
use App\Domain\Billing\Http\PlatformPlansController;
use Illuminate\Support\Facades\Route;

/*
 * Panel interno de la plataforma (hub/CLAUDE.md §16): /api/platform/*, solo
 * superadmin (EnsurePlatformAdmin). Lo carga App\Domain\Billing\ModuleServiceProvider.
 */
Route::get('overview', [PlatformController::class, 'overview']);
Route::get('health', [PlatformController::class, 'health']);
// Diagnóstico de la instalación (lo mismo que igr:doctor).
Route::get('doctor', PlatformDoctorController::class);
// Tasas de cambio contra el dólar (F11): automáticas del día y la manual por moneda.
Route::get('fx', [PlatformExchangeRatesController::class, 'index']);
Route::post('fx/refresh', [PlatformExchangeRatesController::class, 'refresh'])->middleware('throttle:6,1');
Route::put('fx/{currency}', [PlatformExchangeRatesController::class, 'update']);
Route::delete('fx/{currency}', [PlatformExchangeRatesController::class, 'destroy']);
// Correo del sistema: estado y prueba de envío.
Route::get('mail', [PlatformMailController::class, 'show']);
Route::post('mail/test', [PlatformMailController::class, 'test'])->middleware('throttle:6,1');
// Clientes (empresas): alta con espacio, plan e invitación al dueño; detalle, edición, espacios e invitaciones.
Route::post('accounts', [PlatformClientsController::class, 'store']);
Route::get('accounts/{account}', [PlatformClientsController::class, 'show']);
Route::patch('accounts/{account}', [PlatformClientsController::class, 'update']);
Route::post('accounts/{account}/workspaces', [PlatformClientsController::class, 'addWorkspace']);
Route::post('accounts/{account}/invitations', [PlatformClientsController::class, 'invite'])->middleware('throttle:30,1');
// Planes: crear, editar, archivar y restaurar (no se borran).
Route::get('plans', [PlatformPlansController::class, 'index']);
Route::post('plans', [PlatformPlansController::class, 'store']);
Route::patch('plans/{plan}', [PlatformPlansController::class, 'update']);
Route::post('plans/{plan}/{action}', [PlatformPlansController::class, 'archive'])->whereIn('action', ['archive', 'restore']);
Route::put('accounts/{account}/plan', [PlatformController::class, 'assignPlan']);
Route::put('accounts/{account}/billing-status', [PlatformController::class, 'setBillingStatus']);
Route::get('accounts/{account}/invoice-draft', [PlatformController::class, 'invoiceDraft']);
Route::post('accounts/{account}/invoices', [PlatformController::class, 'createInvoice']);
Route::put('workspaces/{workspace}/features/{feature}', [PlatformController::class, 'setFeature']);
Route::post('invoices/{invoice}/{action}', [PlatformController::class, 'invoiceAction'])->whereIn('action', ['send', 'paid', 'void']);
Route::get('invoices/{invoice}/pdf', [PlatformController::class, 'invoicePdf']);
Route::post('plan-requests/{planRequest}/{action}', [PlatformController::class, 'planRequest'])->whereIn('action', ['apply', 'reject']);
