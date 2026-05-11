<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CommissionRateController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::get('/referrals/check', [ReferralController::class, 'checkQuery']);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register/request', [UserController::class, 'sendOtp']);
Route::post('/register/verify-otp', [UserController::class, 'verifyOtp']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'userDashboard']);
    Route::get('/admin/dashboard', [DashboardController::class, 'adminDashboard']);
    Route::get('/admin/users', [AdminController::class, 'indexUsers']);
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateRole']);

    Route::put('/users/{id}/shipping-address', [UserController::class, 'updateShippingAddress']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/become-admin', [UserController::class, 'becomeAdmin']);

    Route::get('/brands', [BrandController::class, 'index']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{id}/commission', [BrandController::class, 'updateCommission']);
    Route::put('/brands/{id}', [BrandController::class, 'update']);
    Route::delete('/brands/{id}', [BrandController::class, 'destroy']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    Route::get('/commission-rate', [CommissionRateController::class, 'show']);
    Route::put('/commission-rate', [CommissionRateController::class, 'update']);

    Route::get('/commissions', [CommissionController::class, 'index']);
    Route::put('/commissions/{id}/status', [CommissionController::class, 'updateStatus']);

    Route::get('/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/withdrawals', [WithdrawalController::class, 'store']);
    Route::put('/withdrawals/{id}/approve', [WithdrawalController::class, 'approve']);
    Route::put('/withdrawals/{id}/complete', [WithdrawalController::class, 'complete']);
    Route::put('/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancel']);
    Route::put('/withdrawals/{id}/restore', [WithdrawalController::class, 'restore']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'buyerCancel']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::put('/products/{id}/commission', [ProductController::class, 'updateCommission']);

    Route::get('/referrals/mine', [ReferralController::class, 'mine']);

    Route::post('/referral/create/{userId}', [ReferralController::class, 'createReferralCode']);
    Route::post('/referral/check', [ReferralController::class, 'checkReferralCode']);
    Route::post('/referral/apply', [ReferralController::class, 'applyReferral']);
    Route::post('/referral/delete', [ReferralController::class, 'deleteReferral']);
});
