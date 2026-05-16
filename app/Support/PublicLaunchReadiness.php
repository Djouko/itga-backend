<?php

namespace App\Support;

class PublicLaunchReadiness
{
    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $environment = (string) config('app.env');
        $isProduction = $environment === 'production';

        $checks = [
            'app_key' => $this->check(
                ! $isProduction || trim((string) config('app.key')) !== '',
                'blocker',
                'APP_KEY must be configured in production.'
            ),
            'app_debug_disabled' => $this->check(
                ! $isProduction || config('app.debug') === false,
                'blocker',
                'APP_DEBUG must be false in production.'
            ),
            'app_url_https' => $this->check(
                ! $isProduction || $this->isHttpsUrl((string) config('app.url')),
                'blocker',
                'APP_URL must be HTTPS in production.'
            ),
            'api_secret_key' => $this->check(
                ! $isProduction || trim($this->apiSecretKey()) !== '',
                'blocker',
                'API_SECRET_KEY must be configured for public clients.'
            ),
            'admin_api_secret_key' => $this->check(
                ! $isProduction || trim($this->adminApiSecretKey()) !== '',
                'blocker',
                'ADMIN_API_SECRET_KEY must be configured for admin APIs.'
            ),
            'readiness_token' => $this->check(
                ! $isProduction || trim((string) config('ops.readiness_token', '')) !== '',
                'blocker',
                'READINESS_TOKEN must protect /api/readiness in production.'
            ),
            'shared_cache' => $this->check(
                ! $isProduction || in_array((string) config('cache.default'), (array) config('ops.production_cache_drivers', []), true),
                'blocker',
                'CACHE_DRIVER must be shared for consistent rate limits and multi-node behavior.'
            ),
            'async_queue' => $this->check(
                ! $isProduction || in_array((string) config('queue.default'), (array) config('ops.production_queue_connections', []), true),
                'blocker',
                'QUEUE_CONNECTION must not be sync in production.'
            ),
            'shared_session' => $this->check(
                ! $isProduction || in_array((string) config('session.driver'), (array) config('ops.production_session_drivers', []), true),
                'blocker',
                'SESSION_DRIVER must be shared for admin/public sessions.'
            ),
            'cloud_or_shared_media_storage' => $this->check(
                ! $isProduction || in_array((string) config('filesystems.default'), (array) config('ops.production_filesystem_disks', []), true),
                'warning',
                'FILESYSTEM_DRIVER should be S3 or another shared disk before multi-node traffic.'
            ),
            'explicit_cors_origins' => $this->check(
                ! $isProduction || $this->hasExplicitCorsOrigins(),
                'warning',
                'CORS allowed origins should be explicit in production.'
            ),
        ];

        $blockers = [];
        $warnings = [];

        foreach ($checks as $name => $check) {
            if ($check['ok'] === true) {
                continue;
            }

            if ($check['severity'] === 'blocker') {
                $blockers[] = $name;
            } else {
                $warnings[] = $name;
            }
        }

        return [
            'ok' => $blockers === [],
            'environment' => $environment,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(bool $ok, string $severity, string $message): array
    {
        return [
            'ok' => $ok,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return strpos(strtolower($url), 'https://') === 0;
    }

    private function apiSecretKey(): string
    {
        return $this->runtimeSecret('ops.api_secret_key', 'API_SECRET_KEY');
    }

    private function adminApiSecretKey(): string
    {
        return $this->runtimeSecret('ops.admin_api_secret_key', 'ADMIN_API_SECRET_KEY');
    }

    private function runtimeSecret(string $configKey, string $envKey): string
    {
        $envValue = getenv($envKey)
            ?: ($_ENV[$envKey] ?? ($_SERVER[$envKey] ?? ''));

        return (string) ($envValue !== '' ? $envValue : config($configKey, ''));
    }

    private function hasExplicitCorsOrigins(): bool
    {
        $origins = (array) config('cors.allowed_origins', []);

        return $origins !== [] && ! in_array('*', $origins, true);
    }
}
