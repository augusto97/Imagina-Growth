<?php

use App\Domain\Surveys\Http\SurveySettingsController;
use Illuminate\Support\Facades\Route;

Route::get('surveys/hdyhau', [SurveySettingsController::class, 'show']);
Route::put('surveys/hdyhau', [SurveySettingsController::class, 'update']);
