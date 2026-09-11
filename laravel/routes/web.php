<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ErpController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\MockupController;

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
});
