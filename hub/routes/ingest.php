<?php

use App\Domain\Privacy\Http\SitePrivacyController;
use App\Domain\Surveys\Http\PublicWidgetsController;
use App\Http\Controllers\Connector\CaddyAskController;
use App\Http\Controllers\Connector\SiteApiController;
use App\Http\Controllers\HealthController;
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
    Route::post('privacy/requests', SitePrivacyController::class);
});

// Interno: Caddy lo consulta antes de emitir TLS bajo demanda (infra/Caddyfile).
Route::get('internal/caddy/ask', CaddyAskController::class);

// Salud para Better Stack (hub/CLAUDE.md §16).
Route::get('health', HealthController::class);

// Widgets públicos del sitio (api-connector.md §8).
Route::get('p/{publicKey}/widgets.json', [PublicWidgetsController::class, 'widgets']);
Route::post('p/{publicKey}/surveys/{surveyId}/answers', [PublicWidgetsController::class, 'answer']);
Route::options('p/{publicKey}/{any}', [PublicWidgetsController::class, 'preflight'])->where('any', '.*');
