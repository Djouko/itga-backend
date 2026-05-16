<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationApiAuthAndContractTest extends TestCase
{
    private const API_KEY = 'notification-auth-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_fetch_user_notification_requires_authorized_user_token(): void
    {
        $response = $this->postJson('/api/fetchUserNotification', [
            'my_user_id' => 1,
            'start' => 0,
            'limit' => 20,
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'error_code' => 'token_not_provided',
            ]);
    }

    public function test_fetch_user_notification_rejects_authenticated_user_mismatch(): void
    {
        $authUser = $this->createUser('auth-user');
        $otherUser = $this->createUser('other-user');

        $response = $this->postJson('/api/fetchUserNotification', [
            'my_user_id' => $otherUser->id,
            'start' => 0,
            'limit' => 20,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'error_code' => 'authenticated_user_mismatch',
            ]);
    }

    public function test_notification_contract_returns_status_and_structured_unread_count(): void
    {
        $owner = $this->createUser('owner-user');
        $actor = $this->createUser('actor-user');

        DB::table('saved_notifications')->insert([
            [
                'my_user_id' => $owner->id,
                'user_id' => $actor->id,
                'type' => 1,
                'is_read' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'my_user_id' => $owner->id,
                'user_id' => $actor->id,
                'type' => 2,
                'is_read' => 0,
                'created_at' => now()->subSecond(),
                'updated_at' => now()->subSecond(),
            ],
            [
                'my_user_id' => $owner->id,
                'user_id' => $actor->id,
                'type' => 3,
                'is_read' => 1,
                'created_at' => now()->subSeconds(2),
                'updated_at' => now()->subSeconds(2),
            ],
        ]);

        $countBefore = $this->postJson('/api/fetchUnreadNotificationCount', [
            'my_user_id' => $owner->id,
        ], $this->apiHeaders($owner));

        $countBefore->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Unread Notification Count',
                'data' => [
                    'count' => 2,
                    'unread_count' => 2,
                ],
            ]);

        $markRead = $this->postJson('/api/markNotificationsAsRead', [
            'my_user_id' => $owner->id,
        ], $this->apiHeaders($owner));

        $markRead->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.updated_count', 2);

        $listResponse = $this->postJson('/api/fetchUserNotification', [
            'my_user_id' => $owner->id,
            'start' => 0,
            'limit' => 50,
        ], $this->apiHeaders($owner));

        $listResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Fetch Saved Notification Successfully')
            ->assertJsonPath('meta.start', 0)
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('meta.count', 3);

        $items = $listResponse->json('data');
        $this->assertCount(3, $items);
        $this->assertTrue(collect($items)->every(static fn ($item) => (int) ($item['is_read'] ?? 0) === 1));

        $countAfter = $this->postJson('/api/fetchUnreadNotificationCount', [
            'my_user_id' => $owner->id,
        ], $this->apiHeaders($owner));

        $countAfter->assertStatus(200)
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.unread_count', 0);
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
        foreach (['saved_notifications', 'post_contents', 'posts', 'reels', 'rooms', 'companies', 'personal_access_tokens', 'users'] as $table) {
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

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->integer('is_suspended')->default(0);
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('post_contents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->timestamps();
        });

        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('my_user_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('message')->nullable();
            $table->integer('type')->nullable();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->unsignedBigInteger('reel_id')->nullable();
            $table->unsignedBigInteger('reel_comment_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function createUser(string $identity): User
    {
        $user = new User();
        $user->identity = $identity;
        $user->username = $identity;
        $user->full_name = $identity;
        $user->is_block = 0;
        $user->save();

        return $user;
    }

    private function apiHeaders(User $user): array
    {
        return [
            'apikey' => self::API_KEY,
            'Authorization' => 'Bearer ' . $user->createToken('notification-auth-test')->plainTextToken,
        ];
    }
}
