<?php

use App\Domain\Referrals\Http\AffiliateController;
use App\Domain\Referrals\Http\AffiliateConversionController;
use App\Domain\Referrals\Http\ReferralController;
use App\Domain\Referrals\Http\ReferralProgramController;
use App\Domain\Referrals\Http\ReferralReportController;
use Illuminate\Support\Facades\Route;

/*
 * Referidos, afiliados e influencers (modulos.md M14). Dentro de /api/w/{workspace} (routes/api.php).
 */

Route::get('referrals/program', [ReferralProgramController::class, 'show']);
Route::put('referrals/program', [ReferralProgramController::class, 'update']);
Route::get('referrals/report', ReferralReportController::class);
Route::get('referrals', [ReferralController::class, 'index']);
Route::post('referrals/{referral}/approve', [ReferralController::class, 'approve']);
Route::post('referrals/{referral}/reject', [ReferralController::class, 'reject']);

Route::get('affiliates', [AffiliateController::class, 'index']);
Route::post('affiliates', [AffiliateController::class, 'store']);
Route::get('affiliates/{affiliate}', [AffiliateController::class, 'show']);
Route::patch('affiliates/{affiliate}', [AffiliateController::class, 'update']);
Route::delete('affiliates/{affiliate}', [AffiliateController::class, 'destroy']);
Route::post('affiliates/{affiliate}/portal-link', [AffiliateController::class, 'portalLink']);
Route::post('affiliates/{affiliate}/store-coupon', [AffiliateController::class, 'storeCoupon']);

Route::get('affiliate-conversions', [AffiliateConversionController::class, 'index']);
Route::get('affiliate-conversions/payouts', [AffiliateConversionController::class, 'payouts']);
Route::get('affiliate-conversions/payouts.csv', [AffiliateConversionController::class, 'export']);
Route::post('affiliate-conversions/mark-paid', [AffiliateConversionController::class, 'markPaid']);
