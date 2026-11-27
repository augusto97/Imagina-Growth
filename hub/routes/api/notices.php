<?php

use App\Domain\Notices\Http\NoticeController;
use Illuminate\Support\Facades\Route;

/*
 * Avisos (lote 0.4.0, docs/avisos.md). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('notices', [NoticeController::class, 'index']);
Route::get('notices/unread', [NoticeController::class, 'unread']);
Route::post('notices/read', [NoticeController::class, 'read']);
Route::get('notices/preferences', [NoticeController::class, 'preferences']);
Route::put('notices/preferences', [NoticeController::class, 'savePreferences']);

// La sección «Avisos» de una cita o un lead, y «Así avisamos» de la agenda.
Route::get('bookings/notices/setup', [NoticeController::class, 'bookingSetup']);
Route::get('bookings/appointments/{appointment}/notices', [NoticeController::class, 'appointment']);
Route::get('leads/{lead}/notices', [NoticeController::class, 'lead']);
