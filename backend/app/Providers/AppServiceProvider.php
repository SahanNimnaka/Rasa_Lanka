<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\AnnotationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(3)->by($request->ip());
        });

        Gate::define('annotation.access', [AnnotationPolicy::class, 'access']);
        Gate::define('annotation.validate', [AnnotationPolicy::class, 'validate']);
        Gate::define('annotation.approve', [AnnotationPolicy::class, 'approve']);
        Gate::define('annotation.export-canonical', [AnnotationPolicy::class, 'exportCanonical']);
    }
}
