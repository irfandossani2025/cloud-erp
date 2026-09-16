<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ErpController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\MockupController;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\ProductController;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/local-preview', [AuthController::class, 'local'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::view('/', 'erp');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/api/erp', [ErpController::class, 'index']);
    Route::post('/api/erp', [ErpController::class, 'store'])->middleware('throttle:60,1');
    Route::post('/api/ai', [AiController::class, 'draft'])->middleware('throttle:10,1');
    Route::get('/api/mockups', [MockupController::class, 'index']);
    Route::post('/api/mockups', [MockupController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/api/assets', [MockupController::class, 'asset']);
    Route::get('/api/messages', [MessagingController::class, 'index']);
    Route::post('/api/messages', [MessagingController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/api/messages/thread', [MessagingController::class, 'thread']);
    Route::post('/api/messages/send', [MessagingController::class, 'send'])->middleware('throttle:30,1');
    Route::get('/api/messages/asset', [MessagingController::class, 'asset']);
    Route::post('/api/products/{id}/photo', [ProductController::class, 'storePhoto'])->middleware('throttle:20,1');
    Route::get('/api/product-assets/{id}', [ProductController::class, 'asset']);
});
