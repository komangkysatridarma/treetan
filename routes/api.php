<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController; 
use App\Http\Controllers\UserController; 
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/webhook/xendit', [PaymentController::class, 'webhook']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('products', ProductController::class); 
    Route::apiResource('users', UserController::class); 
    Route::apiResource('orders', OrderController::class);
    Route::apiResource('order-items', OrderItemController::class);
    Route::apiResource('categories', CategoryController::class);
    
    Route::apiResource('payments', PaymentController::class)->only([
        'index',
        'store',
        'show',
        'destroy'
    ]);
});