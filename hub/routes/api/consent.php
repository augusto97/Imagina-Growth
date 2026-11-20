<?php

use App\Domain\Consent\Http\ConsentTextsController;
use Illuminate\Support\Facades\Route;

Route::get('consent/texts', [ConsentTextsController::class, 'index']);
Route::post('consent/texts', [ConsentTextsController::class, 'store']);
Route::put('consent/mode', [ConsentTextsController::class, 'mode']);
Route::put('consent/early-capture', [ConsentTextsController::class, 'earlyCapture']);
