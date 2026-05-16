<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReelReportAuthTest extends TestCase
{
    private const API_KEY = 'reel-report-auth-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_report_reel_requires_authorized_user_token(): void
    {
        $reelId = $this->createReel();

        $response = $this->postJson('/api/reportReel', [
            'reel_id' => $reelId,
            'reason' => 'spam',
            'desc' => 'Reel report without auth token',
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'error_code' => 'token_not_provided',
            ]);
    }

    public function test_report_reel_rejects_authenticated_user_mismatch(): void
    {
        $authUser = $this->createUser(['identity' => 'reel-auth-user']);
        $otherUser = $this->createUser(['identity' => 'reel-other-user']);
        $reelId = $this->createReel();

        $response = $this->postJson('/api/reportReel', [
            'user_id' => $otherUser->id,
            'reel_id' => $reelId,
            'reason' => 'abuse',
            'desc' => 'Mismatch test',
        ], $this->apiHeaders($authUser));

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'error_code' => 'authenticated_user_mismatch',
            ]);

        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_report_reel_stores_authenticated_user_as_reporter(): void
    {
        $authUser = $this->createUser(['identity' => 'reel-reporter']);
        $reelId = $this->createReel();

        $response = $this->postJson('/api/reportReel', [
            'reel_id' => $reelId,
            'reason' => 'harassment',
            'desc' => 'Harmful reel details',
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Report Added Successfully.',
            ]);

        $savedReport = DB::table('reports')->first();
        $this->assertNotNull($savedReport);
        $this->assertSame($authUser->id, (int) $savedReport->user_id);
        $this->assertSame($reelId, (int) $savedReport->reel_id);
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
        foreach (['reports', 'reels', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->integer('is_block')->default(0);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('desc')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->integer('type')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('reel_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reason')->nullable();
            $table->text('desc')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(array $overrides = []): User
    {
        $user = new User();
        $user->identity = $overrides['identity'] ?? ('identity-' . uniqid());
        $user->username = $overrides['username'] ?? ('user' . uniqid());
        $user->full_name = $overrides['full_name'] ?? 'Reel Report Test User';
        $user->is_block = $overrides['is_block'] ?? 0;
        $user->save();

        return $user;
    }

    private function createReel(): int
    {
        return (int) DB::table('reels')->insertGetId([
            'desc' => 'Reported reel sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function apiHeaders(User $user): array
    {
        return [
            'apikey' => self::API_KEY,
            'Authorization' => 'Bearer ' . $user->createToken('reel-report-auth-test')->plainTextToken,
        ];
    }
}
