<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomReportAuthTest extends TestCase
{
    private const API_KEY = 'room-report-auth-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_report_room_requires_authorized_user_token(): void
    {
        $roomId = $this->createRoom();

        $response = $this->postJson('/api/reportRoom', [
            'room_id' => $roomId,
            'reason' => 'spam',
            'desc' => 'Report without auth token',
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'error_code' => 'token_not_provided',
            ]);
    }

    public function test_report_room_rejects_authenticated_user_mismatch(): void
    {
        $authUser = $this->createUser(['identity' => 'auth-user']);
        $otherUser = $this->createUser(['identity' => 'other-user']);
        $roomId = $this->createRoom();

        $response = $this->postJson('/api/reportRoom', [
            'user_id' => $otherUser->id,
            'room_id' => $roomId,
            'reason' => 'abuse',
            'desc' => 'Mismatch actor test',
        ], $this->apiHeaders($authUser));

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'error_code' => 'authenticated_user_mismatch',
            ]);

        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_report_room_requires_membership_in_reported_room(): void
    {
        $authUser = $this->createUser(['identity' => 'member-user']);
        $roomToReport = $this->createRoom();
        $otherRoom = $this->createRoom();

        // User belongs to another room but not the room being reported.
        $this->attachMember($otherRoom, $authUser->id);

        $response = $this->postJson('/api/reportRoom', [
            'room_id' => $roomToReport,
            'reason' => 'harassment',
            'desc' => 'Cannot report room without being a member',
        ], $this->apiHeaders($authUser));

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'error_code' => 'room_report_not_member',
            ]);

        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_report_room_stores_authenticated_member_as_reporter(): void
    {
        $authUser = $this->createUser(['identity' => 'room-reporter']);
        $roomId = $this->createRoom();
        $this->attachMember($roomId, $authUser->id);

        $response = $this->postJson('/api/reportRoom', [
            'room_id' => $roomId,
            'reason' => 'unsafe behavior',
            'desc' => 'Room moderation issue details',
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Report Added Successfully',
            ]);

        $savedReport = DB::table('reports')->first();
        $this->assertNotNull($savedReport);
        $this->assertSame(0, (int) $savedReport->type);
        $this->assertSame($roomId, (int) $savedReport->room_id);
        $this->assertSame($authUser->id, (int) $savedReport->user_id);
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
        foreach (['reports', 'room_users', 'rooms', 'personal_access_tokens', 'users'] as $table) {
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

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('room_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->integer('type')->default(2);
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
        $user->full_name = $overrides['full_name'] ?? 'Room Report Test User';
        $user->is_block = $overrides['is_block'] ?? 0;
        $user->save();

        return $user;
    }

    private function createRoom(): int
    {
        return (int) DB::table('rooms')->insertGetId([
            'title' => 'Reported room sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachMember(int $roomId, int $userId): void
    {
        DB::table('room_users')->insert([
            'room_id' => $roomId,
            'user_id' => $userId,
            'company_id' => null,
            'type' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function apiHeaders(User $user): array
    {
        return [
            'apikey' => self::API_KEY,
            'Authorization' => 'Bearer ' . $user->createToken('room-report-auth-test')->plainTextToken,
        ];
    }
}
