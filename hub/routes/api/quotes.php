<?php

use App\Domain\Billing\Http\RequirePlanModule;
use App\Domain\Quotes\Http\QuoteController;
use Illuminate\Support\Facades\Route;

/*
 * Cotizador (modulos.md M18). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('quotes/calculators', [QuoteController::class, 'index']);
Route::post('quotes/calculators', [QuoteController::class, 'save'])->middleware(RequirePlanModule::class.':quotes');
Route::get('quotes/calculators/{calculator}', [QuoteController::class, 'show']);
Route::patch('quotes/calculators/{calculator}', [QuoteController::class, 'save']);
Route::delete('quotes/calculators/{calculator}', [QuoteController::class, 'destroy']);
Route::post('quotes/calculators/{calculator}/preview', [QuoteController::class, 'preview']);
Route::get('quotes/calculators/{calculator}/qr', [QuoteController::class, 'qr'])->middleware('throttle:60,1');
Route::get('quotes/requests', [QuoteController::class, 'requests']);

// El asistente: plantillas por oficio, la propuesta con IA (no guarda), ejemplos de cálculo y la vista previa de la página.
Route::get('quotes/templates', [QuoteController::class, 'templates']);
Route::post('quotes/preview', [QuoteController::class, 'previewDraft'])->middleware('throttle:120,1');
Route::post('quotes/assistant', [QuoteController::class, 'assistant'])->middleware([RequirePlanModule::class.':quotes', 'throttle:10,1']);
Route::post('quotes/page-preview', [QuoteController::class, 'pagePreviewStore'])->middleware('throttle:120,1');
Route::get('quotes/page-preview/{token}', [QuoteController::class, 'pagePreview'])->where('token', '[A-Za-z0-9]{32}');
Route::get('quotes/page-preview/{token}/result', [QuoteController::class, 'pagePreviewResult'])->where('token', '[A-Za-z0-9]{32}');
