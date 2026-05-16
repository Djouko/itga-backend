<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModerationAppealApiTest extends TestCase
{
    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_submit_moderation_appeal_rejects_authenticated_user_mismatch(): void
    {
        $this->createUser(1, 0);
        DB::table('moderation_audit_logs')->insert([
            'id' => 1,
            'moderator_user_id' => 7,
            'action' => 'delete_post',
            'target_type' => 'post',
            'target_id' => 100,
            'target_owner_user_id' => 1,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'spam']),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->issueTokenForUser(1);

        $response = $this->postJson('/api/ModerationAppeal/submit', [
            'user_id' => 99,
            'audit_log_id' => 1,
            'reason' => 'Please re-check this decision.',
        ], $this->apiHeaders($token));

        $response->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('error_code', 'authenticated_user_mismatch');
    }

    public function test_submit_and_fetch_my_moderation_appeals(): void
    {
        $this->createUser(10, 0);
        DB::table('moderation_audit_logs')->insert([
            'id' => 10,
            'moderator_user_id' => 8,
            'action' => 'delete_comment',
            'target_type' => 'comment',
            'target_id' => 200,
            'target_owner_user_id' => 10,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'abuse']),
            'ip_address' => '127.0.0.8',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->issueTokenForUser(10);

        $submitResponse = $this->postJson('/api/ModerationAppeal/submit', [
            'user_id' => 10,
            'audit_log_id' => 10,
            'reason' => 'I want to appeal this moderation action.',
            'details' => 'Context was misunderstood.',
        ], $this->apiHeaders($token));

        $submitResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.audit_log_id', 10);

        $fetchResponse = $this->postJson('/api/ModerationAppeal/fetchMyAppeals', [
            'user_id' => 10,
            'start' => 0,
            'limit' => 20,
            'status' => 'pending',
        ], $this->apiHeaders($token));

        $fetchResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.total_filtered', 1)
            ->assertJsonPath('data.0.audit_log_id', 10)
            ->assertJsonPath('data.0.audit_log.action', 'delete_comment');
    }

    public function test_submit_moderation_appeal_rejects_duplicate_pending_appeal(): void
    {
        $this->createUser(12, 0);
        DB::table('moderation_audit_logs')->insert([
            'id' => 12,
            'moderator_user_id' => 3,
            'action' => 'block_user',
            'target_type' => 'user',
            'target_id' => 12,
            'target_owner_user_id' => 12,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'policy']),
            'ip_address' => '127.0.0.3',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('moderation_audit_appeals')->insert([
            'audit_log_id' => 12,
            'appellant_user_id' => 12,
            'status' => 'pending',
            'reason' => 'First pending appeal',
            'details' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->issueTokenForUser(12);

        $response = $this->postJson('/api/ModerationAppeal/submit', [
            'user_id' => 12,
            'audit_log_id' => 12,
            'reason' => 'Second appeal should fail',
        ], $this->apiHeaders($token));

        $response->assertStatus(200)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'A pending appeal already exists for this moderation decision.');
    }

    private function configureInMemoryDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    private function configureApiKey(): void
    {
        putenv('API_SECRET_KEY=' . self::API_KEY);
        $_ENV['API_SECRET_KEY'] = self::API_KEY;
        $_SERVER['API_SECRET_KEY'] = self::API_KEY;
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('moderation_audit_appeals');
        Schema::dropIfExists('moderation_audit_logs');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('is_block')->default(0);
            $table->unsignedTinyInteger('is_moderator')->default(0);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::create('moderation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderator_user_id');
            $table->string('action', 80);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('target_owner_user_id')->nullable();
            $table->string('status', 40)->default('success');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('moderation_audit_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_log_id')->index();
            $table->unsignedBigInteger('appellant_user_id')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('reason', 500);
            $table->text('details')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('reviewed_by', 120)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(int $id, int $isBlock): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'is_block' => $isBlock,
            'is_moderator' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueTokenForUser(int $userId): string
    {
        $plainToken = 'token-' . $userId . '-' . Str::random(20);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $userId,
            'name' => 'test-token',
            'token' => hash('sha256', $plainToken),
            'abilities' => json_encode(['*']),
            'last_used_at' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plainToken;
    }

    private function apiHeaders(string $token): array
    {
        return [
            'apikey' => self::API_KEY,
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
