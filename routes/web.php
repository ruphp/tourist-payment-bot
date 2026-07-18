<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/telegram', TelegramWebhookController::class)
    ->name('webhooks.telegram');
