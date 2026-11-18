<?php

use App\Domain\Intelligence\Ai\Copilot\Http\CopilotController;
use Illuminate\Support\Facades\Route;

/*
 * Copiloto de campañas y flujos y redactor (M10, inteligencia.md §6.4–6.5).
 * Dentro de /api/w/{workspace} (routes/api.php). Lo que llama al modelo va con un
 * límite por persona.
 */
Route::get('copilot/status', [CopilotController::class, 'status']);
Route::get('copilot/drafts', [CopilotController::class, 'index']);
Route::get('copilot/drafts/{draft}', [CopilotController::class, 'show']);
Route::patch('copilot/drafts/{draft}', [CopilotController::class, 'update']);
Route::post('copilot/drafts/{draft}/apply', [CopilotController::class, 'apply']);
Route::post('copilot/drafts/{draft}/discard', [CopilotController::class, 'discard']);
Route::middleware('throttle:20,1')->group(function () {
    Route::post('copilot/campaigns', [CopilotController::class, 'campaign']);
    Route::post('copilot/flows', [CopilotController::class, 'flow']);
    Route::post('copilot/content/subjects', [CopilotController::class, 'subjects']);
    Route::post('copilot/content/whatsapp', [CopilotController::class, 'whatsapp']);
    Route::post('copilot/content/variants', [CopilotController::class, 'variants']);
    Route::post('copilot/tone', [CopilotController::class, 'learnTone']);
});
