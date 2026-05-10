<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Models\Product;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReferralController;

//Registration
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register/request', [UserController::class, 'sendOtp']);
Route::post('/register/verify-otp', [UserController::class, 'verifyOtp']);
Route::post('/login', [AuthController::class, 'login']);

//USer
Route::get('/users/{id}', [UserController::class, 'show']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);

// PRODUCTS
Route::get('/products', [ProductController::class, 'index']);
Route::post('/products', [ProductController::class, 'store']);
Route::put('/products/{id}', [ProductController::class, 'update']);
Route::delete('/products/{id}', [ProductController::class, 'destroy']);
Route::put('/products/{id}/commission', [ProductController::class, 'updateCommission']);

// REFERRALS
Route::post('/referral/create/{userId}', [ReferralController::class, 'createReferralCode']);
Route::post('/referral/check', [ReferralController::class, 'checkReferralCode']);
Route::post('/referral/apply', [ReferralController::class, 'applyReferral']);
Route::post('/referral/delete', [ReferralController::class, 'deleteReferral']);