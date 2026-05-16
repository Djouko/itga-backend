<?php

namespace Tests\Feature;

use App\Models\Constants;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModeratorHttpSecurityTest extends TestCase
{
    private const API_KEY = 'moderator-http-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_moderator_http_route_rejects_missing_api_key(): void
    {
        $moderator = $this->createUser(Constants::moderator);

        $response = $this->postJson('/api/Moderator/deletePostByModerator', [
            'user_id' => $moderator->id,
            'post_id' => 1,
        ], [
            'Authorization' => 'Bearer ' . $moderator->createToken('moderator-test')->plainTextToken,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'message' => 'Unauthorized Access',
            ]);
    }

    public function test_moderator_http_route_rejects_missing_bearer_token(): void
    {
        $response = $this->postJson('/api/Moderator/deletePostByModerator', [
            'user_id' => 1,
            'post_id' => 1,
        ], $this->apiHeaders());

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'error_code' => 'token_not_provided',
            ]);
    }

    public function test_moderator_http_routes_reject_actor_mismatch(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $headers = $this->apiHeaders($moderator);

        foreach ($this->moderatorRoutes() as $uri => $payload) {
            $response = $this->postJson('/api/Moderator/' . $uri, $payload, $headers);

            $response->assertStatus(403)
                ->assertJson([
                    'status' => false,
                    'error_code' => 'authenticated_user_mismatch',
                ]);
        }
    }

    public function test_moderator_http_route_rejects_non_moderator_actor(): void
    {
        $user = $this->createUser(Constants::moderator_false);

        $response = $this->postJson('/api/Moderator/deletePostByModerator', [
            'user_id' => $user->id,
            'post_id' => 1,
        ], $this->apiHeaders($user));

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => 'User is not a Moderator',
            ]);
    }

    public function test_moderator_http_route_returns_clean_response_for_missing_post(): void
    {
        $moderator = $this->createUser(Constants::moderator);

        $response = $this->postJson('/api/Moderator/deletePostByModerator', [
            'user_id' => $moderator->id,
            'post_id' => 999,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => 'Post Not Found',
            ]);
    }

    public function test_moderator_http_route_returns_clean_response_for_missing_reel(): void
    {
        $moderator = $this->createUser(Constants::moderator);

        $response = $this->postJson('/api/Moderator/deleteReelByModerator', [
            'user_id' => $moderator->id,
            'reel_id' => 999,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200);
        $this->assertFalse($response->json('status'));
    }

    public function test_successful_post_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $postId = $this->createPost($owner);

        $response = $this->postJson('/api/Moderator/deletePostByModerator', [
            'user_id' => $moderator->id,
            'post_id' => $postId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('posts', ['id' => $postId]);
        $this->assertAuditLog($moderator->id, 'delete_post', 'post', $postId, $owner->id);
    }

    public function test_successful_comment_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $postId = $this->createPost($owner);
        $commentId = $this->createComment($owner, $postId);

        $response = $this->postJson('/api/Moderator/deleteCommentByModerator', [
            'user_id' => $moderator->id,
            'comment_id' => $commentId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('comments', ['id' => $commentId]);
        $this->assertAuditLog($moderator->id, 'delete_comment', 'comment', $commentId, $owner->id);
    }

    public function test_successful_room_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $roomId = $this->createRoom($owner);

        $response = $this->postJson('/api/Moderator/deleteRoomByModerator', [
            'user_id' => $moderator->id,
            'room_id' => $roomId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('rooms', ['id' => $roomId]);
        $this->assertAuditLog($moderator->id, 'delete_room', 'room', $roomId, $owner->id);
    }

    public function test_successful_story_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $storyId = $this->createStory($owner);

        $response = $this->postJson('/api/Moderator/deleteStoryByModerator', [
            'user_id' => $moderator->id,
            'story_id' => $storyId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('stories', ['id' => $storyId]);
        $this->assertAuditLog($moderator->id, 'delete_story', 'story', $storyId, $owner->id);
    }

    public function test_successful_user_block_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $target = $this->createUser(Constants::moderator_false);

        $response = $this->postJson('/api/Moderator/userBlockByModerator', [
            'user_id' => $moderator->id,
            'to_user_id' => $target->id,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_block' => Constants::blocked]);
        $this->assertAuditLog($moderator->id, 'block_user', 'user', $target->id, $target->id);
    }

    public function test_successful_reel_comment_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $reelId = $this->createReel($owner);
        $reelCommentId = $this->createReelComment($owner, $reelId);

        $response = $this->postJson('/api/Moderator/deleteReelCommentByModerator', [
            'user_id' => $moderator->id,
            'reel_comment_id' => $reelCommentId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('reel_comments', ['id' => $reelCommentId]);
        $this->assertAuditLog($moderator->id, 'delete_reel_comment', 'reel_comment', $reelCommentId, $owner->id);
    }

    public function test_successful_reel_delete_creates_moderation_audit_log(): void
    {
        $moderator = $this->createUser(Constants::moderator);
        $owner = $this->createUser(Constants::moderator_false);
        $reelId = $this->createReel($owner);

        $response = $this->postJson('/api/Moderator/deleteReelByModerator', [
            'user_id' => $moderator->id,
            'reel_id' => $reelId,
        ], $this->apiHeaders($moderator));

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertDatabaseMissing('reels', ['id' => $reelId]);
        $this->assertAuditLog($moderator->id, 'delete_reel', 'reel', $reelId, $owner->id);
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
        foreach (['moderation_audit_logs', 'saved_notifications', 'personal_access_tokens', 'reel_comments', 'reels', 'stories', 'room_users', 'rooms', 'reports', 'likes', 'post_contents', 'comments', 'posts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->string('bio')->nullable();
            $table->string('interests')->nullable();
            $table->string('profile')->nullable();
            $table->string('background_image')->nullable();
            $table->integer('following')->nullable();
            $table->integer('followers')->nullable();
            $table->integer('login_type')->nullable();
            $table->integer('device_type')->nullable();
            $table->string('onesignal_player_id')->nullable();
            $table->integer('is_moderator')->default(Constants::moderator_false);
            $table->integer('is_block')->default(Constants::unblocked);
            $table->integer('is_push_notifications')->default(0);
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

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('desc')->nullable();
            $table->integer('comments_count')->nullable();
            $table->integer('likes_count')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('post_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('desc')->nullable();
            $table->timestamps();
        });

        Schema::create('post_contents', function (Blueprint $table) {
            $table->id();
            $table->integer('post_id')->nullable();
            $table->string('content')->nullable();
            $table->string('thumb')->nullable();
            $table->timestamps();
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('post_id')->nullable();
            $table->integer('reel_id')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->integer('room_id')->nullable();
            $table->integer('post_id')->nullable();
            $table->integer('reel_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('reason')->nullable();
            $table->integer('type')->nullable();
            $table->string('desc')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->integer('admin_id')->nullable();
            $table->string('photo')->nullable();
            $table->string('title')->nullable();
            $table->string('desc')->nullable();
            $table->string('interests')->nullable();
            $table->integer('room_status')->nullable();
            $table->integer('join_after_request')->nullable();
            $table->integer('total_member')->default(0);
            $table->timestamps();
        });

        Schema::create('room_users', function (Blueprint $table) {
            $table->id();
            $table->integer('room_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->string('content')->nullable();
            $table->timestamps();
        });

        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->string('content')->nullable();
            $table->string('thumbnail')->nullable();
            $table->integer('comments_count')->nullable();
            $table->integer('likes_count')->nullable();
            $table->timestamps();
        });

        Schema::create('reel_comments', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('reel_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('my_user_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->string('message')->nullable();
            $table->integer('type')->nullable();
            $table->integer('post_id')->nullable();
            $table->integer('comment_id')->nullable();
            $table->integer('reel_id')->nullable();
            $table->integer('reel_comment_id')->nullable();
            $table->integer('room_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function createUser(int $isModerator): User
    {
        $id = DB::table('users')->insertGetId([
            'identity' => 'test-user-' . uniqid(),
            'username' => 'test_user_' . uniqid(),
            'full_name' => 'Test User',
            'login_type' => 0,
            'device_type' => 0,
            'onesignal_player_id' => 'test-player',
            'is_moderator' => $isModerator,
            'is_block' => Constants::unblocked,
            'is_push_notifications' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function createPost(User $owner): int
    {
        return DB::table('posts')->insertGetId([
            'user_id' => $owner->id,
            'desc' => 'Flagged post',
            'comments_count' => 0,
            'likes_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createComment(User $owner, int $postId): int
    {
        return DB::table('comments')->insertGetId([
            'user_id' => $owner->id,
            'post_id' => $postId,
            'desc' => 'Flagged comment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRoom(User $owner): int
    {
        return DB::table('rooms')->insertGetId([
            'admin_id' => $owner->id,
            'title' => 'Flagged room',
            'desc' => 'Flagged room description',
            'interests' => '1',
            'room_status' => 1,
            'join_after_request' => 0,
            'total_member' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createStory(User $owner): int
    {
        return DB::table('stories')->insertGetId([
            'user_id' => $owner->id,
            'content' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createReel(User $owner): int
    {
        return DB::table('reels')->insertGetId([
            'user_id' => $owner->id,
            'content' => null,
            'thumbnail' => null,
            'comments_count' => 0,
            'likes_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createReelComment(User $owner, int $reelId): int
    {
        return DB::table('reel_comments')->insertGetId([
            'user_id' => $owner->id,
            'reel_id' => $reelId,
            'description' => 'Flagged reel comment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertAuditLog(int $moderatorId, string $action, string $targetType, int $targetId, int $targetOwnerUserId): void
    {
        $this->assertDatabaseHas('moderation_audit_logs', [
            'moderator_user_id' => $moderatorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_owner_user_id' => $targetOwnerUserId,
            'status' => 'success',
        ]);
    }

    private function apiHeaders(?User $user = null): array
    {
        $headers = ['apikey' => self::API_KEY];

        if ($user) {
            $headers['Authorization'] = 'Bearer ' . $user->createToken('moderator-test')->plainTextToken;
        }

        return $headers;
    }

    private function moderatorRoutes(): array
    {
        return [
            'deletePostByModerator' => ['user_id' => 999, 'post_id' => 1],
            'deleteCommentByModerator' => ['user_id' => 999, 'comment_id' => 1],
            'deleteRoomByModerator' => ['user_id' => 999, 'room_id' => 1],
            'deleteStoryByModerator' => ['user_id' => 999, 'story_id' => 1],
            'userBlockByModerator' => ['user_id' => 999, 'to_user_id' => 2],
            'deleteReelCommentByModerator' => ['user_id' => 999, 'reel_comment_id' => 1],
            'deleteReelByModerator' => ['user_id' => 999, 'reel_id' => 1],
        ];
    }
}
