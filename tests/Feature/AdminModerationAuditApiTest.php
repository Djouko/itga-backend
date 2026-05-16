<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminModerationAuditApiTest extends TestCase
{
    private const API_KEY = 'test-api-key';
    private const ADMIN_API_KEY = 'test-admin-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKeys();
        $this->createSchema();
    }

    public function test_admin_moderation_audit_api_rejects_missing_admin_key(): void
    {
        $response = $this->postJson('/api/AdminModeration/fetchAuditLogs', [
            'start' => 0,
            'limit' => 20,
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Unauthorized Admin Access',
            ]);
    }

    public function test_admin_moderation_audit_api_returns_filtered_logs(): void
    {
        DB::table('moderation_audit_logs')->insert([
            [
                'moderator_user_id' => 10,
                'action' => 'delete_post',
                'target_type' => 'post',
                'target_id' => 1001,
                'target_owner_user_id' => 99,
                'status' => 'success',
                'metadata' => json_encode(['comments_count' => 1]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
            ],
            [
                'moderator_user_id' => 11,
                'action' => 'delete_reel',
                'target_type' => 'reel',
                'target_id' => 2002,
                'target_owner_user_id' => 88,
                'status' => 'success',
                'metadata' => json_encode(['likes_count' => 5]),
                'ip_address' => '127.0.0.2',
                'user_agent' => 'PHPUnit',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
        ]);

        $response = $this->postJson('/api/AdminModeration/fetchAuditLogs', [
            'start' => 0,
            'limit' => 20,
            'action' => 'delete_post',
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Success',
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.total_filtered', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'delete_post')
            ->assertJsonPath('data.0.target_type', 'post');
    }

    public function test_admin_moderation_export_api_masks_sensitive_data_by_default(): void
    {
        DB::table('moderation_audit_logs')->insert([
            'moderator_user_id' => 10,
            'action' => 'delete_post',
            'target_type' => 'post',
            'target_id' => 1001,
            'target_owner_user_id' => 99,
            'status' => 'success',
            'metadata' => json_encode(['comments_count' => 1]),
            'ip_address' => '127.0.0.10',
            'user_agent' => 'PHPUnit-Sensitive',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/AdminModeration/exportAuditLogs', [
            'format' => 'json',
            'max_rows' => 100,
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Success',
            ])
            ->assertJsonPath('meta.include_sensitive', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip_address', null)
            ->assertJsonPath('data.0.user_agent', null);
    }

    public function test_admin_moderation_export_api_requires_legal_hold_for_sensitive_export(): void
    {
        config()->set('moderation.allow_sensitive_export', true);

        $response = $this->postJson('/api/AdminModeration/exportAuditLogs', [
            'include_sensitive' => true,
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => 'legal_hold_reason is required for sensitive export.',
            ]);
    }

    public function test_admin_moderation_export_api_rejects_sensitive_export_when_disabled(): void
    {
        config()->set('moderation.allow_sensitive_export', false);

        $response = $this->postJson('/api/AdminModeration/exportAuditLogs', [
            'include_sensitive' => true,
            'legal_hold_reason' => 'Legal dispute retention',
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Sensitive export is disabled in this environment.',
            ]);
    }

    public function test_admin_moderation_appeal_apis_fetch_and_review_pending_appeal(): void
    {
        DB::table('moderation_audit_logs')->insert([
            'id' => 1,
            'moderator_user_id' => 10,
            'action' => 'delete_post',
            'target_type' => 'post',
            'target_id' => 1001,
            'target_owner_user_id' => 99,
            'status' => 'success',
            'metadata' => json_encode(['comments_count' => 1]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        DB::table('moderation_audit_appeals')->insert([
            'id' => 2,
            'audit_log_id' => 1,
            'appellant_user_id' => 99,
            'status' => 'pending',
            'reason' => 'Decision should be reviewed',
            'details' => 'The content was compliant.',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $fetchResponse = $this->postJson('/api/AdminModeration/fetchAuditAppeals', [
            'start' => 0,
            'limit' => 20,
            'status' => 'pending',
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $fetchResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.total_filtered', 1)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.audit_log_id', 1)
            ->assertJsonPath('data.0.audit_log.action', 'delete_post');

        $reviewResponse = $this->postJson('/api/AdminModeration/reviewAuditAppeal', [
            'appeal_id' => 2,
            'decision' => 'approved',
            'resolution_note' => 'Approved after manual review.',
            'reviewed_by' => 'compliance-admin',
        ], [
            'apikey' => self::API_KEY,
            'x-admin-key' => self::ADMIN_API_KEY,
        ]);

        $reviewResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', 2)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', 'compliance-admin');

        $this->assertSame('approved', DB::table('moderation_audit_appeals')->where('id', 2)->value('status'));
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

    private function configureApiKeys(): void
    {
        putenv('API_SECRET_KEY=' . self::API_KEY);
        $_ENV['API_SECRET_KEY'] = self::API_KEY;
        $_SERVER['API_SECRET_KEY'] = self::API_KEY;

        putenv('ADMIN_API_SECRET_KEY=' . self::ADMIN_API_KEY);
        $_ENV['ADMIN_API_SECRET_KEY'] = self::ADMIN_API_KEY;
        $_SERVER['ADMIN_API_SECRET_KEY'] = self::ADMIN_API_KEY;
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('moderation_audit_appeals');
        Schema::dropIfExists('moderation_audit_logs');

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
}
