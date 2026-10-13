<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ResumeController;
use App\Http\Controllers\Api\V1\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/paystack/webhook', PaystackWebhookController::class)
    ->name('api.v1.paystack.webhook');

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:registration')
        ->name('api.v1.auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/user', [AuthController::class, 'user'])->name('api.v1.auth.user');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

        Route::get('/profile', [ProfileController::class, 'show'])->name('api.v1.profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('api.v1.profile.update');

        Route::apiResource('resumes', ResumeController::class)->only(['index', 'show', 'destroy']);
        Route::post('/resumes', [ResumeController::class, 'store'])
            ->middleware('throttle:resume-uploads')
            ->name('resumes.store');

        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

        Route::apiResource('reviews', ReviewController::class)->only(['index', 'store', 'show']);
        Route::get('/reviews/{review}/download', [ReviewController::class, 'download'])
            ->name('reviews.download');
    });
});
