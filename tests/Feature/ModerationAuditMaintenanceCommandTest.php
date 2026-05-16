<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModerationAuditMaintenanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->createSchema();
    }

    public function test_maintenance_command_anonymizes_and_deletes_expected_logs(): void
    {
        $deletableId = DB::table('moderation_audit_logs')->insertGetId([
            'moderator_user_id' => 1,
            'action' => 'delete_post',
            'target_type' => 'post',
            'target_id' => 101,
            'target_owner_user_id' => 11,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'spam']),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'LegacyAgent',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        $anonymizeOnlyId = DB::table('moderation_audit_logs')->insertGetId([
            'moderator_user_id' => 2,
            'action' => 'delete_reel',
            'target_type' => 'reel',
            'target_id' => 202,
            'target_owner_user_id' => 22,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'abuse']),
            'ip_address' => '10.0.0.2',
            'user_agent' => 'MidAgent',
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $freshId = DB::table('moderation_audit_logs')->insertGetId([
            'moderator_user_id' => 3,
            'action' => 'block_user',
            'target_type' => 'user',
            'target_id' => 303,
            'target_owner_user_id' => 33,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'threat']),
            'ip_address' => '10.0.0.3',
            'user_agent' => 'FreshAgent',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->artisan('moderation:audit-maintenance', [
            '--retention-days' => 365,
            '--anonymize-days' => 90,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('moderation_audit_logs', ['id' => $deletableId]);

        $this->assertDatabaseHas('moderation_audit_logs', [
            'id' => $anonymizeOnlyId,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->assertDatabaseHas('moderation_audit_logs', [
            'id' => $freshId,
            'ip_address' => '10.0.0.3',
            'user_agent' => 'FreshAgent',
        ]);
    }

    public function test_maintenance_command_dry_run_keeps_data_unchanged(): void
    {
        $oldId = DB::table('moderation_audit_logs')->insertGetId([
            'moderator_user_id' => 4,
            'action' => 'delete_comment',
            'target_type' => 'comment',
            'target_id' => 404,
            'target_owner_user_id' => 44,
            'status' => 'success',
            'metadata' => json_encode(['reason' => 'spam']),
            'ip_address' => '10.0.0.4',
            'user_agent' => 'DryRunAgent',
            'created_at' => now()->subDays(500),
            'updated_at' => now()->subDays(500),
        ]);

        $this->artisan('moderation:audit-maintenance', [
            '--retention-days' => 365,
            '--anonymize-days' => 90,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('moderation_audit_logs', [
            'id' => $oldId,
            'ip_address' => '10.0.0.4',
            'user_agent' => 'DryRunAgent',
        ]);
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

    private function createSchema(): void
    {
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
    }
}
