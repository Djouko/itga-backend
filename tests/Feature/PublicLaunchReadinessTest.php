<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLaunchReadinessTest extends TestCase
{
    public function test_public_readiness_command_blocks_unsafe_production_configuration(): void
    {
        $this->setSecretEnv('', '');

        $this->configureProduction([
            'app.debug' => true,
            'app.key' => '',
            'app.url' => 'http://itga.example.test',
            'ops.api_secret_key' => '',
            'ops.admin_api_secret_key' => '',
            'ops.readiness_token' => '',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
        ]);

        $this->artisan('ops:public-readiness')
            ->expectsOutput('ITGA public launch readiness')
            ->assertExitCode(1);
    }

    public function test_public_readiness_command_accepts_minimum_safe_production_configuration(): void
    {
        $this->setSecretEnv('public-api-key', 'admin-api-key');

        $this->configureProduction([
            'app.debug' => false,
            'app.key' => 'base64:fake-public-launch-key',
            'app.url' => 'https://itga.example.test',
            'ops.api_secret_key' => 'public-api-key',
            'ops.admin_api_secret_key' => 'admin-api-key',
            'ops.readiness_token' => 'readiness-token',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.driver' => 'redis',
            'filesystems.default' => 's3',
            'cors.allowed_origins' => ['https://itga.example.test'],
        ]);

        $this->artisan('ops:public-readiness')
            ->expectsOutput('ITGA public launch readiness')
            ->assertExitCode(0);
    }

    public function test_readiness_endpoint_exposes_public_launch_blockers_to_authorized_probe(): void
    {
        $this->setSecretEnv('', '');

        $this->configureProduction([
            'ops.readiness_token' => 'readiness-token',
            'app.debug' => true,
            'app.key' => '',
            'app.url' => 'http://itga.example.test',
            'ops.api_secret_key' => '',
            'ops.admin_api_secret_key' => '',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
        ]);

        $this->getJson('/api/readiness', ['x-readiness-token' => 'readiness-token'])
            ->assertStatus(503)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.checks.public_launch.ok', false)
            ->assertJsonPath('data.checks.public_launch.checks.app_debug_disabled.ok', false)
            ->assertJsonPath('data.checks.public_launch.checks.api_secret_key.ok', false)
            ->assertJsonPath('data.checks.public_launch.checks.admin_api_secret_key.ok', false);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureProduction(array $overrides): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:fake-key',
            'app.url' => 'https://itga.example.test',
            'ops.api_secret_key' => 'public-api-key',
            'ops.admin_api_secret_key' => 'admin-api-key',
            'ops.readiness_token' => 'readiness-token',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.driver' => 'redis',
            'filesystems.default' => 's3',
            'cors.allowed_origins' => ['https://itga.example.test'],
        ], $overrides));
    }

    private function setSecretEnv(string $apiKey, string $adminApiKey): void
    {
        putenv('API_SECRET_KEY=' . $apiKey);
        $_ENV['API_SECRET_KEY'] = $apiKey;
        $_SERVER['API_SECRET_KEY'] = $apiKey;

        putenv('ADMIN_API_SECRET_KEY=' . $adminApiKey);
        $_ENV['ADMIN_API_SECRET_KEY'] = $adminApiKey;
        $_SERVER['ADMIN_API_SECRET_KEY'] = $adminApiKey;
    }
}
