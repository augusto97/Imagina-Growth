<?php

use App\Http\Controllers\Connector\SiteApiController;
use App\Http\Middleware\VerifySiteSignature;
use App\Ingest\Http\BrowserIngestController;
use App\Ingest\Http\ServerIngestController;
use Illuminate\Support\Facades\Route;

/*
 * Ingesta y API firmada del connector (api-connector.md §4 y §5).
 * Sin sesión, cookies ni CSRF: van fuera del grupo web.
 */

Route::post('v1/s', ServerIngestController::class);
Route::post('v1/b', BrowserIngestController::class);
Route::options('v1/b', [BrowserIngestController::class, 'preflight']);

Route::middleware(VerifySiteSignature::class)->prefix('v1/s')->group(function (): void {
    Route::get('config', [SiteApiController::class, 'config']);
    Route::get('catalog/hashes', [SiteApiController::class, 'catalogHashes']);
    Route::put('backfill', [SiteApiController::class, 'backfill']);
});
