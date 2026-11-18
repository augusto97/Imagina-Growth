<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Bookings\Http\BookingController;
use Illuminate\Support\Facades\Route;

/*
 * Agenda de citas (modulos.md M18). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('bookings', [BookingController::class, 'overview']);
Route::put('bookings/settings', [BookingController::class, 'settings']);
Route::put('bookings/availability', [BookingController::class, 'availability']);
Route::post('bookings/services', [BookingController::class, 'saveService'])->middleware(RequirePlanModule::class.':bookings');
Route::patch('bookings/services/{service}', [BookingController::class, 'saveService']);
Route::delete('bookings/services/{service}', [BookingController::class, 'deleteService']);
Route::post('bookings/staff', [BookingController::class, 'saveStaff']);
Route::patch('bookings/staff/{staff}', [BookingController::class, 'saveStaff']);
Route::delete('bookings/staff/{staff}', [BookingController::class, 'deleteStaff']);
Route::match(['post', 'delete'], 'bookings/staff/{staff}/google', [BookingController::class, 'google']);
Route::get('bookings/slots', [BookingController::class, 'slots']);
Route::get('bookings/metrics', [BookingController::class, 'metrics']);
Route::get('bookings/appointments', [BookingController::class, 'appointments']);
Route::post('bookings/appointments', [BookingController::class, 'book'])->middleware(RequirePlanModule::class.':bookings');
Route::post('bookings/appointments/{appointment}/{action}', [BookingController::class, 'change'])
    ->whereIn('action', ['confirm', 'complete', 'no-show', 'cancel', 'reschedule']);
