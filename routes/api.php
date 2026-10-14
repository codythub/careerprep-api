<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\ResumeController as AdminResumeController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ResumeController;
use App\Http\Controllers\Api\V1\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/paystack/webhook', PaystackWebhookController::class)
    ->name('api.v1.paystack.webhook');

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::get('/pricing', PricingController::class)->name('api.v1.pricing');

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

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

        Route::apiResource('reviews', ReviewController::class)->only(['index', 'store', 'show']);
        Route::post('/reviews/{review}/retry', [ReviewController::class, 'retry'])->name('reviews.retry');
        Route::get('/reviews/{review}/download', [ReviewController::class, 'download'])
            ->name('reviews.download');

        Route::prefix('admin')->middleware('admin')->name('api.v1.admin.')->group(function (): void {
            Route::get('/stats', AdminStatsController::class)->name('stats');

            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');

            Route::get('/resumes', [AdminResumeController::class, 'index'])->name('resumes.index');
            Route::get('/resumes/{resume}', [AdminResumeController::class, 'show'])->name('resumes.show');
            Route::get('/resumes/{resume}/download', [AdminResumeController::class, 'download'])->name('resumes.download');

            Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
            Route::post('/payments/{payment}/reverify', [AdminPaymentController::class, 'reverify'])->name('payments.reverify');
            Route::post('/payments/{payment}/mark-refunded', [AdminPaymentController::class, 'markRefunded'])->name('payments.mark-refunded');

            Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
            Route::get('/reviews/{review}', [AdminReviewController::class, 'show'])->name('reviews.show');
            Route::post('/reviews/{review}/retry', [AdminReviewController::class, 'retry'])->name('reviews.retry');
            Route::post('/reviews/{review}/grant-attempt', [AdminReviewController::class, 'grantAttempt'])->name('reviews.grant-attempt');

            Route::get('/audit-log', AdminAuditLogController::class)->name('audit-log');
        });
    });
});
