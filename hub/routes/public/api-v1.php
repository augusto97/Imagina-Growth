<?php

use App\Domain\Api\Http\Middleware\AuthenticateApiClient;
use App\Domain\Api\Http\Middleware\FormatApiErrors;
use App\Domain\Api\Http\Middleware\Idempotent;
use App\Domain\Api\Http\Middleware\RequireScope;
use App\Domain\Api\Http\Middleware\ThrottleApiClient;
use App\Domain\Api\Http\V1\AnalyticsController;
use App\Domain\Api\Http\V1\CampaignsController;
use App\Domain\Api\Http\V1\ContactsController;
use App\Domain\Api\Http\V1\EventsController;
use App\Domain\Api\Http\V1\LeadsController;
use App\Domain\Api\Http\V1\MeController;
use App\Domain\Api\Http\V1\OrdersController;
use App\Domain\Api\Http\V1\SegmentsController;
use App\Domain\Api\Http\V1\VitalsController;
use App\Domain\Api\Support\ApiError;
use App\Domain\Webhooks\Http\WebhookApiController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
 * API pública v1 (M19, docs/api-publica.md): token del espacio de trabajo
 * (`Authorization: Bearer igr_…`) con alcances, límite por minuto, errores
 * `{error: {code, message, fields?}}` y `Idempotency-Key` en las escrituras.
 * Sin sesión ni CSRF. La documenta Scramble (`api_path = api/v1`).
 */

$scope = fn (string $s): string => RequireScope::class.':'.$s;

Route::prefix('api/v1')
    ->middleware([FormatApiErrors::class, AuthenticateApiClient::class, ThrottleApiClient::class, SubstituteBindings::class])
    ->name('api.v1.')
    ->group(function () use ($scope): void {
        Route::get('me', MeController::class)->name('me');

        Route::get('contacts', [ContactsController::class, 'index'])->middleware($scope('contacts:read'))->name('contacts.index');
        Route::post('contacts', [ContactsController::class, 'upsert'])->middleware([$scope('contacts:write'), Idempotent::class])->name('contacts.upsert');
        Route::get('contacts/{contact}', [ContactsController::class, 'show'])->middleware($scope('contacts:read'))->name('contacts.show');

        Route::post('events', [EventsController::class, 'store'])->middleware([$scope('events:write'), Idempotent::class])->name('events.store');
        Route::post('vitals', [VitalsController::class, 'store'])->middleware([$scope('events:write'), Idempotent::class])->name('vitals.store');

        Route::get('orders', [OrdersController::class, 'index'])->middleware($scope('orders:read'))->name('orders.index');
        Route::get('orders/{order}', [OrdersController::class, 'show'])->middleware($scope('orders:read'))->name('orders.show');

        Route::get('segments', [SegmentsController::class, 'index'])->middleware($scope('segments:read'))->name('segments.index');
        Route::get('segments/{segment}', [SegmentsController::class, 'show'])->middleware($scope('segments:read'))->name('segments.show');

        Route::post('campaigns', [CampaignsController::class, 'store'])->middleware([$scope('campaigns:write'), Idempotent::class])->name('campaigns.store');

        Route::get('leads', [LeadsController::class, 'index'])->middleware($scope('leads:read'))->name('leads.index');
        Route::post('leads', [LeadsController::class, 'store'])->middleware([$scope('leads:write'), Idempotent::class])->name('leads.store');
        Route::get('leads/{lead}', [LeadsController::class, 'show'])->middleware($scope('leads:read'))->name('leads.show');
        Route::patch('leads/{lead}', [LeadsController::class, 'update'])->middleware([$scope('leads:write'), Idempotent::class])->name('leads.update');

        Route::get('analytics/catalog', [AnalyticsController::class, 'catalog'])->middleware($scope('analytics:read'))->name('analytics.catalog');
        Route::post('analytics/query', [AnalyticsController::class, 'query'])->middleware($scope('analytics:read'))->name('analytics.query');
        Route::get('analytics/kpis', [AnalyticsController::class, 'kpis'])->middleware($scope('analytics:read'))->name('analytics.kpis');

        // Suscripciones REST de Zapier, Make y n8n (docs/api-publica.md §10).
        Route::get('webhooks', [WebhookApiController::class, 'index'])->middleware($scope('webhooks:manage'))->name('webhooks.index');
        Route::post('webhooks', [WebhookApiController::class, 'store'])->middleware([$scope('webhooks:manage'), Idempotent::class])->name('webhooks.store');
        Route::delete('webhooks/{webhook}', [WebhookApiController::class, 'destroy'])->middleware($scope('webhooks:manage'))->name('webhooks.destroy');

        Route::fallback(fn () => ApiError::make(404, 'not_found', __('Esa ruta no existe en la API v1.')));
    });
