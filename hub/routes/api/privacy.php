<?php

use App\Domain\Privacy\Http\DataRequestController;
use Illuminate\Support\Facades\Route;

Route::get('data-requests', [DataRequestController::class, 'index']);
Route::post('contacts/{contact}/data-requests', [DataRequestController::class, 'store']);
Route::post('data-requests/{dataRequest}/execute', [DataRequestController::class, 'execute']);
Route::get('data-requests/{dataRequest}/download', [DataRequestController::class, 'download']);
