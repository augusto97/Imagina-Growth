<?php

declare(strict_types=1);

use App\Domain\Agency\Http\PublicBrandingController;
use Illuminate\Support\Facades\Route;

// M22: la marca de la pantalla de ingreso según el dominio (marca blanca).
Route::get('api/public/branding', PublicBrandingController::class)->middleware('throttle:120,1');
