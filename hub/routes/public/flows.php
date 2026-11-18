<?php

use App\Domain\Flows\Http\FlowWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook de entrada de un flujo (modulos.md M09, «Disparadores»): el token va en la URL.
Route::post('webhooks/flows/{token}', FlowWebhookController::class)->middleware('throttle:120,1')->where('token', '[A-Za-z0-9_.]+');
