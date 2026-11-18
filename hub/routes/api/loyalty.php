<?php

use App\Domain\Loyalty\Http\LoyaltyController;
use Illuminate\Support\Facades\Route;

/*
 * Fidelización (modulos.md M13). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('loyalty', [LoyaltyController::class, 'show']);
Route::put('loyalty/program', [LoyaltyController::class, 'saveProgram']);
Route::put('loyalty/tiers', [LoyaltyController::class, 'saveTiers']);
Route::post('loyalty/rewards', [LoyaltyController::class, 'storeReward']);
Route::patch('loyalty/rewards/{reward}', [LoyaltyController::class, 'updateReward']);
Route::delete('loyalty/rewards/{reward}', [LoyaltyController::class, 'archiveReward']);
Route::get('loyalty/stats', [LoyaltyController::class, 'stats']);
Route::get('loyalty/members', [LoyaltyController::class, 'members']);
Route::get('loyalty/contacts/{contact}', [LoyaltyController::class, 'contact']);
Route::post('loyalty/contacts/{contact}/adjust', [LoyaltyController::class, 'adjust']);
