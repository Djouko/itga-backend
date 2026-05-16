<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpsHealthReadinessTest extends TestCase
{
    public function test_health_endpoint_is_public_and_lightweight(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'ok')
            ->assertJsonPath('data.service', 'itga-api');
    }

    public function test_readiness_rejects_missing_token_when_configured(): void
    {
        config(['ops.readiness_token' => 'test-readiness-token']);

        $this->getJson('/api/readiness')
            ->assertStatus(403)
            ->assertJsonPath('status', false);
    }

    public function test_readiness_accepts_valid_token_in_testing_runtime(): void
    {
        config([
            'ops.readiness_token' => 'test-readiness-token',
            'app.env' => 'testing',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);

        $this->getJson('/api/readiness', ['x-readiness-token' => 'test-readiness-token'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'ready')
            ->assertJsonPath('data.checks.runtime.ok', true);
    }

    public function test_readiness_flags_non_distributed_production_runtime(): void
    {
        config([
            'ops.readiness_token' => 'test-readiness-token',
            'app.env' => 'production',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
        ]);

        $this->getJson('/api/readiness', ['x-readiness-token' => 'test-readiness-token'])
            ->assertStatus(503)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'not ready')
            ->assertJsonPath('data.checks.runtime.ok', false)
            ->assertJsonPath('data.checks.runtime.requirements.shared_cache', false)
            ->assertJsonPath('data.checks.runtime.requirements.async_queue', false)
            ->assertJsonPath('data.checks.runtime.requirements.shared_session', false);
    }
}
