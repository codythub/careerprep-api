<?php

namespace App\Providers;

use App\Contracts\ResumeAssessor;
use App\Contracts\ResumeTextExtractor;
use App\Services\NativePdfTextExtractor;
use App\Services\RuleBasedResumeAssessor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ResumeTextExtractor::class, NativePdfTextExtractor::class);
        $this->app->bind(ResumeAssessor::class, RuleBasedResumeAssessor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('registration', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('resume-uploads', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier());
        });
    }
}
