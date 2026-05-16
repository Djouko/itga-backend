<?php

namespace App\Http\Controllers;

use App\Support\PublicLaunchReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class OpsController extends Controller
{
    private PublicLaunchReadiness $publicLaunchReadiness;

    public function __construct(PublicLaunchReadiness $publicLaunchReadiness)
    {
        $this->publicLaunchReadiness = $publicLaunchReadiness;
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'service' => 'itga-api',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->hasValidReadinessToken($request)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized readiness probe.',
            ], 403);
        }

        $checks = [
            'cache' => $this->checkCache(),
            'runtime' => $this->checkRuntimeConfiguration(),
            'public_launch' => $this->publicLaunchReadiness->report(),
        ];

        $ready = collect($checks)->every(static fn (array $check): bool => $check['ok'] === true);

        return response()->json([
            'status' => $ready,
            'message' => $ready ? 'ready' : 'not ready',
            'data' => [
                'service' => 'itga-api',
                'timestamp' => now()->toIso8601String(),
                'checks' => $checks,
            ],
        ], $ready ? 200 : 503);
    }

    private function hasValidReadinessToken(Request $request): bool
    {
        $expectedToken = (string) config('ops.readiness_token', '');

        if ($expectedToken === '') {
            return app()->environment(['local', 'testing']);
        }

        $providedToken = (string) $request->header('x-readiness-token', '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCache(): array
    {
        $driver = (string) config('cache.default');

        try {
            $key = 'ops:readiness:' . Str::random(16);

            Cache::put($key, 'ok', now()->addSeconds(10));
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return [
                'ok' => $ok,
                'driver' => $driver,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'driver' => $driver,
                'error' => 'cache_unavailable',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRuntimeConfiguration(): array
    {
        $environment = (string) config('app.env');
        $cacheDriver = (string) config('cache.default');
        $queueConnection = (string) config('queue.default');
        $sessionDriver = (string) config('session.driver');

        $productionCacheDrivers = (array) config('ops.production_cache_drivers', []);
        $productionQueueConnections = (array) config('ops.production_queue_connections', []);
        $productionSessionDrivers = (array) config('ops.production_session_drivers', []);

        $isProduction = $environment === 'production';
        $cacheIsShared = in_array($cacheDriver, $productionCacheDrivers, true);
        $queueIsAsync = in_array($queueConnection, $productionQueueConnections, true);
        $sessionIsShared = in_array($sessionDriver, $productionSessionDrivers, true);

        return [
            'ok' => ! $isProduction || ($cacheIsShared && $queueIsAsync && $sessionIsShared),
            'environment' => $environment,
            'cache_driver' => $cacheDriver,
            'queue_connection' => $queueConnection,
            'session_driver' => $sessionDriver,
            'requirements' => [
                'shared_cache' => $isProduction ? $cacheIsShared : true,
                'async_queue' => $isProduction ? $queueIsAsync : true,
                'shared_session' => $isProduction ? $sessionIsShared : true,
            ],
        ];
    }
}
