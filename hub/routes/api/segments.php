<?php

use App\Domain\Segments\Http\SegmentController;
use App\Domain\Segments\Http\SegmentOptionsController;
use Illuminate\Support\Facades\Route;

/*
 * Segmentación (modulos.md M04). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('segments', [SegmentController::class, 'index']);
Route::post('segments', [SegmentController::class, 'store']);
Route::post('segments/preview', [SegmentController::class, 'preview']);
Route::post('segments/static', [SegmentController::class, 'storeStatic']);
Route::get('segments/library', [SegmentController::class, 'library']);
Route::post('segments/library/{key}', [SegmentController::class, 'fromTemplate'])->where('key', '[a-z_]+');
Route::get('segments/options', [SegmentOptionsController::class, 'index']);
Route::get('segments/options/products', [SegmentOptionsController::class, 'products']);
Route::get('segments/options/categories', [SegmentOptionsController::class, 'categories']);
Route::get('segments/{segment}', [SegmentController::class, 'show']);
Route::patch('segments/{segment}', [SegmentController::class, 'update']);
Route::delete('segments/{segment}', [SegmentController::class, 'destroy']);
Route::get('segments/{segment}/members', [SegmentController::class, 'members']);
Route::get('segments/{segment}/export', [SegmentController::class, 'export']);
Route::post('segments/{segment}/recompute', [SegmentController::class, 'recompute']);
Route::post('segments/{segment}/snapshot', [SegmentController::class, 'snapshot']);
