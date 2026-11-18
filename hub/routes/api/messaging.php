<?php

use App\Domain\Messaging\Http\Api\ChannelController;
use App\Domain\Messaging\Http\Api\ContactMessagesController;
use App\Domain\Messaging\Http\Api\SuppressionController;
use App\Domain\Messaging\Http\Api\WaTemplateController;
use Illuminate\Support\Facades\Route;

// M07: canales, plantillas de WhatsApp, supresión y mensajes del contacto (incluido en /api/w/{workspace}).
Route::get('channels', [ChannelController::class, 'index']);
Route::post('channels/email', [ChannelController::class, 'storeEmail']);
Route::post('channels/whatsapp/embedded-signup', [ChannelController::class, 'embeddedSignup']);
Route::post('channels/whatsapp/manual', [ChannelController::class, 'whatsappManual']);
Route::post('channels/whatsapp/zender', [ChannelController::class, 'zender']);
Route::post('channels/sms/twilio', [ChannelController::class, 'twilio']);
Route::get('channels/{channel}/dns', [ChannelController::class, 'dns']);
Route::post('channels/{channel}/sync', [ChannelController::class, 'sync']);
Route::post('channels/{channel}/test', [ChannelController::class, 'test']);
Route::patch('channels/{channel}', [ChannelController::class, 'update']);
Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);

Route::get('wa-templates', [WaTemplateController::class, 'index']);
Route::post('wa-templates', [WaTemplateController::class, 'store']);
Route::put('wa-templates/{template}', [WaTemplateController::class, 'update']);
Route::delete('wa-templates/{template}', [WaTemplateController::class, 'destroy']);

Route::get('suppressions', [SuppressionController::class, 'index']);
Route::post('suppressions', [SuppressionController::class, 'store']);
Route::post('suppressions/registry', [SuppressionController::class, 'registry']);
Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->whereNumber('suppression');

Route::get('contacts/{contact}/messages', ContactMessagesController::class);
