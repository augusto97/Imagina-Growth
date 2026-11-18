<?php

use App\Http\Controllers\Connector\ExchangeController;
use App\Http\Controllers\Spa\AuditLogController;
use App\Http\Controllers\Spa\ContactController;
use App\Http\Controllers\Spa\InvitationController;
use App\Http\Controllers\Spa\MeController;
use App\Http\Controllers\Spa\MemberController;
use App\Http\Controllers\Spa\OverviewController;
use App\Http\Controllers\Spa\SiteController;
use App\Http\Controllers\Spa\WorkspaceController;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Support\Facades\Route;

/*
 * API interna de la SPA (/api/...) con sesión de Sanctum, y el canje del
 * código de conexión del connector (api-connector.md §3.3).
 */

Route::post('connect/exchange', ExchangeController::class)->middleware('throttle:connect');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', MeController::class);
    Route::post('workspaces', [WorkspaceController::class, 'store']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);

    Route::prefix('w/{workspace}')->middleware(ResolveWorkspace::class)->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'show']);
        Route::patch('/', [WorkspaceController::class, 'update']);
        Route::get('overview', OverviewController::class);

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}', [MemberController::class, 'update']);
        Route::delete('members/{member}', [MemberController::class, 'destroy']);
        Route::post('invitations', [MemberController::class, 'invite']);
        Route::delete('invitations/{invitation}', [MemberController::class, 'revokeInvitation']);

        Route::get('sites', [SiteController::class, 'index']);
        Route::post('sites/connect-codes', [SiteController::class, 'connectCode']);
        Route::get('sites/{site}', [SiteController::class, 'show']);
        Route::post('sites/{site}/rotate-secret', [SiteController::class, 'rotate']);
        Route::post('sites/{site}/disconnect', [SiteController::class, 'disconnect']);
        Route::post('sites/{site}/commands/{command}', [SiteController::class, 'command'])->where('command', '[a-z_.]+');

        Route::get('contacts', [ContactController::class, 'index']);
        Route::get('audit-logs', AuditLogController::class);
    });
});
