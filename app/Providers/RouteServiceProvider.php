<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($this->resolveRateLimitActor($request, 'api'));
        });

        // Strict limit for file uploads (posts, reels, stories, profile photos)
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($this->resolveRateLimitActor($request, 'uploads'));
        });

        // Moderate limit for write operations (comments, likes, follows)
        RateLimiter::for('writes', function (Request $request) {
            return Limit::perMinute(30)->by($this->resolveRateLimitActor($request, 'writes'));
        });

        // Lightweight engagement events (views/read-style activity) can be frequent.
        RateLimiter::for('engagement', function (Request $request) {
            return Limit::perMinute(120)->by($this->resolveRateLimitActor($request, 'engagement'));
        });

        // Moderate limit for moderation actions (delete/block/review actions).
        RateLimiter::for('moderation', function (Request $request) {
            return Limit::perMinute(45)->by($this->resolveRateLimitActor($request, 'moderation'));
        });

        // Admin-facing moderation endpoints (high privilege, less frequent calls).
        RateLimiter::for('adminWrites', function (Request $request) {
            return Limit::perMinute(60)->by($this->resolveRateLimitActor($request, 'admin-writes'));
        });

        // Very strict limit for auth/sensitive endpoints
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Search endpoints — heavier DB queries, stricter limit
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(20)->by($this->resolveRateLimitActor($request, 'search'));
        });
    }

    private function resolveRateLimitActor(Request $request, string $scope): string
    {
        $authenticatedUserId = $request->attributes->get('authenticated_user_id');
        if (!empty($authenticatedUserId)) {
            return $scope . ':uid:' . $authenticatedUserId;
        }

        $user = $request->user();
        if ($user && !empty($user->id)) {
            return $scope . ':uid:' . $user->id;
        }

        $adminKey = (string) ($request->header('x-admin-key') ?? '');
        if ($adminKey !== '') {
            return $scope . ':admin:' . hash('sha256', $adminKey);
        }

        $authToken = (string) ($request->bearerToken() ?: $request->header('authtoken'));
        if ($authToken !== '') {
            return $scope . ':token:' . hash('sha256', $authToken);
        }

        $payloadUserId = (string) ($request->input('user_id') ?? '');
        if ($payloadUserId !== '') {
            return $scope . ':payload_uid:' . $payloadUserId;
        }

        return $scope . ':ip:' . $request->ip();
    }
}
