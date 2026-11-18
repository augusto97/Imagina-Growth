<?php

use App\Domain\Inbox\Http\InboxController;
use App\Domain\Inbox\Http\InboxSettingsController;
use App\Domain\Inbox\Http\QuickReplyController;
use Illuminate\Support\Facades\Route;

/*
 * Bandeja de conversaciones (modulos.md M11). Dentro de /api/w/{workspace} (routes/api.php).
 */

$cnv = 'cnv_[0-9A-Z]{26}';
Route::get('inbox/conversations', [InboxController::class, 'index']);
Route::get('inbox/conversations/{conversation}', [InboxController::class, 'show'])->where('conversation', $cnv);
Route::get('inbox/conversations/{conversation}/panel', [InboxController::class, 'panel'])->where('conversation', $cnv);
Route::post('inbox/conversations/{conversation}/messages', [InboxController::class, 'send'])->where('conversation', $cnv)->middleware('throttle:120,1');
Route::post('inbox/conversations/{conversation}/assign', [InboxController::class, 'assign'])->where('conversation', $cnv);
Route::patch('inbox/conversations/{conversation}', [InboxController::class, 'update'])->where('conversation', $cnv);
Route::post('inbox/conversations/{conversation}/handoff', [InboxController::class, 'handoff'])->where('conversation', $cnv);
Route::post('inbox/conversations/{conversation}/read', [InboxController::class, 'read'])->where('conversation', $cnv);
Route::post('inbox/conversations/{conversation}/notes', [InboxController::class, 'note'])->where('conversation', $cnv);
Route::post('inbox/conversations/{conversation}/suggest', [InboxController::class, 'suggest'])->where('conversation', $cnv)->middleware('throttle:30,1');
Route::get('inbox/products', [InboxController::class, 'products']);
Route::get('inbox/templates', [InboxController::class, 'templates']);
Route::get('inbox/agents', [InboxController::class, 'agents']);
Route::post('inbox/presence', [InboxController::class, 'presence']);
Route::get('inbox/metrics', [InboxController::class, 'metrics']);
Route::get('inbox/settings', [InboxSettingsController::class, 'show']);
Route::put('inbox/settings', [InboxSettingsController::class, 'update']);
Route::post('inbox/push/subscriptions', [InboxSettingsController::class, 'subscribe']);
Route::delete('inbox/push/subscriptions', [InboxSettingsController::class, 'unsubscribe']);
Route::get('inbox/quick-replies', [QuickReplyController::class, 'index']);
Route::post('inbox/quick-replies', [QuickReplyController::class, 'store']);
Route::put('inbox/quick-replies/{reply}', [QuickReplyController::class, 'update'])->where('reply', 'qr_[0-9A-Z]{26}');
Route::delete('inbox/quick-replies/{reply}', [QuickReplyController::class, 'destroy'])->where('reply', 'qr_[0-9A-Z]{26}');
