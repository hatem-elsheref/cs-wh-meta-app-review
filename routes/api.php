<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MetaSettingsController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WebhookLogController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::get('/webhook/whatsapp', [WebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
        Route::post('/{id}/approve', [UserController::class, 'approve']);
        Route::post('/{id}/reject', [UserController::class, 'reject']);
    })->middleware('admin');

    Route::prefix('settings')->group(function () {
        Route::get('/', [MetaSettingsController::class, 'index']);
        Route::post('/', [MetaSettingsController::class, 'store']);
        Route::get('/verify', [MetaSettingsController::class, 'verifyConfig']);
        Route::get('/webhook-url', [MetaSettingsController::class, 'getWebhookUrl']);
    })->middleware('admin');

    Route::prefix('ai-settings')->group(function () {
        Route::get('/', [AiSettingsController::class, 'index']);
        Route::post('/', [AiSettingsController::class, 'store']);
    })->middleware('admin');

    Route::prefix('templates')->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/sync', [TemplateController::class, 'sync']);
        Route::get('/{id}', [TemplateController::class, 'show']);
    });

    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::put('/{id}', [ContactController::class, 'update']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
    });

    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::get('/{id}', [ConversationController::class, 'show']);
        Route::get('/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('/{id}/send', [ConversationController::class, 'sendMessage']);
    });

    Route::prefix('flow')->group(function () {
        Route::get('/', [FlowController::class, 'show'])->middleware('admin');
        Route::put('/', [FlowController::class, 'update'])->middleware('admin');
    });

    Route::prefix('messages')->group(function () {
        Route::get('/{id}/media', [MediaController::class, 'download']);
    });

    Route::prefix('webhook-logs')->group(function () {
        Route::get('/', [WebhookLogController::class, 'index']);
        Route::get('/{id}', [WebhookLogController::class, 'show']);
    });
});
