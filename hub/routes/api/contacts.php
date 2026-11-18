<?php

use App\Domain\Contacts\Http\ImportController;
use App\Domain\Contacts\Http\ProfileController;
use Illuminate\Support\Facades\Route;

// Perfil 360 (M03). La lista sigue en ContactController (routes/api.php).
Route::post('contacts/imports/preview', [ImportController::class, 'preview']);
Route::post('contacts/imports', [ImportController::class, 'store']);
Route::get('contacts/imports', [ImportController::class, 'index']);
Route::get('contacts/imports/{import}', [ImportController::class, 'show']);
Route::get('contacts/{contact}', [ProfileController::class, 'show']);
Route::patch('contacts/{contact}', [ProfileController::class, 'update']);
Route::get('contacts/{contact}/timeline', [ProfileController::class, 'timeline']);
Route::get('contacts/{contact}/orders', [ProfileController::class, 'orders']);
Route::get('contacts/{contact}/products', [ProfileController::class, 'products']);
Route::get('contacts/{contact}/identities', [ProfileController::class, 'identities']);
Route::get('contacts/{contact}/privacy', [ProfileController::class, 'privacy']);
Route::post('contacts/{contact}/consents', [ProfileController::class, 'consent']);
Route::get('contacts/{contact}/notes', [ProfileController::class, 'notes']);
Route::post('contacts/{contact}/notes', [ProfileController::class, 'addNote']);
Route::delete('notes/{note}', [ProfileController::class, 'deleteNote']);
Route::post('contacts/{contact}/merge', [ProfileController::class, 'merge']);
Route::post('merges/{merge}/revert', [ProfileController::class, 'revert']);
