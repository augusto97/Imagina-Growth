<?php

use App\Domain\Flows\Http\FlowController;
use Illuminate\Support\Facades\Route;

/*
 * Flujos (modulos.md M09). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('flows', [FlowController::class, 'index']);
Route::post('flows', [FlowController::class, 'store']);
Route::get('flows/options', [FlowController::class, 'options']);
Route::get('flows/library', [FlowController::class, 'library']);
Route::post('flows/library/{key}', [FlowController::class, 'fromLibrary'])->where('key', '[a-z_]+');
Route::get('flows/{flow}', [FlowController::class, 'show']);
Route::patch('flows/{flow}', [FlowController::class, 'update']);
Route::delete('flows/{flow}', [FlowController::class, 'destroy']);
Route::post('flows/{flow}/publish', [FlowController::class, 'publish']);
Route::post('flows/{flow}/{action}', [FlowController::class, 'status'])->where('action', 'activate|pause|archive');
Route::post('flows/{flow}/restore/{version}', [FlowController::class, 'restore']);
Route::get('flows/{flow}/versions/{version}', [FlowController::class, 'version']);
Route::get('flows/{flow}/analytics', [FlowController::class, 'analytics']);
Route::get('flows/{flow}/runs', [FlowController::class, 'runs']);
Route::post('flows/{flow}/test', [FlowController::class, 'test'])->middleware('throttle:20,1');
Route::post('flows/{flow}/enroll', [FlowController::class, 'enroll']);
