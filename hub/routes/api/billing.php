<?php

use App\Domain\Billing\Http\BillingController;
use Illuminate\Support\Facades\Route;

// Plan y facturación del espacio de trabajo (M21).
Route::get('billing', [BillingController::class, 'show']);
Route::post('billing/usage/refresh', [BillingController::class, 'refresh'])->middleware('throttle:10,1');
Route::post('billing/plan-request', [BillingController::class, 'requestPlan'])->middleware('throttle:5,1');
Route::get('billing/invoices/{invoice}/pdf', [BillingController::class, 'pdf']);
