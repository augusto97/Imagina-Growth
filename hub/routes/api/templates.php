<?php

use App\Domain\Templates\Http\TemplateController;
use Illuminate\Support\Facades\Route;

/*
 * Plantillas y contenido (modulos.md M08). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('templates', [TemplateController::class, 'index']);
Route::post('templates', [TemplateController::class, 'store']);
Route::post('templates/preview', [TemplateController::class, 'previewDraft']);
Route::post('templates/test-send', [TemplateController::class, 'testSend'])->middleware('throttle:10,1');
Route::get('templates/variables', [TemplateController::class, 'variables']);
Route::get('templates/library', [TemplateController::class, 'library']);
// Galería por objetivo y vertical (rediseño v2 §11.5); se instala con POST templates/library/{key}.
Route::get('templates/gallery', [TemplateController::class, 'gallery']);
Route::get('templates/library/{key}/preview', [TemplateController::class, 'libraryPreview'])->where('key', '[a-z0-9_]+');
Route::post('templates/library/{key}', [TemplateController::class, 'install'])->where('key', '[a-z0-9_]+');
Route::get('templates/wa-sources', [TemplateController::class, 'waSources']);
Route::get('templates/business-profile', [TemplateController::class, 'businessProfile']);
Route::put('templates/business-profile', [TemplateController::class, 'updateBusinessProfile']);
Route::get('templates/{template}', [TemplateController::class, 'show']);
Route::patch('templates/{template}', [TemplateController::class, 'update']);
Route::delete('templates/{template}', [TemplateController::class, 'destroy']);
Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
Route::post('templates/{template}/preview', [TemplateController::class, 'preview']);
