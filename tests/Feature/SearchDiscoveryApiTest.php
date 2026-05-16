<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SearchDiscoveryApiTest extends TestCase
{
    private const API_KEY = 'search-discovery-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->configureApiKey();
        $this->createSchema();
    }

    public function test_search_hashtag_filters_tags_by_keyword(): void
    {
        $authUser = $this->createUser();
        $author = $this->createUser();

        $this->createPost($author->id, 'laravel,php');
        $this->createPost($author->id, 'laravel,vue');
        $this->createPost($author->id, 'flutter,dart');

        $response = $this->postJson('/api/searchHashtag', [
            'user_id' => $authUser->id,
            'keyword' => 'lar',
            'start' => 0,
            'limit' => 10,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Search Hashtag Successfully',
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('laravel', $data[0]['tag']);
        $this->assertSame(2, $data[0]['post_count']);
    }

    public function test_search_hashtag_applies_start_and_limit_after_sorting(): void
    {
        $authUser = $this->createUser();
        $author = $this->createUser();

        $this->createPost($author->id, 'laravel,php');
        $this->createPost($author->id, 'laravel,php');
        $this->createPost($author->id, 'laravel,dart');

        $response = $this->postJson('/api/searchHashtag', [
            'user_id' => $authUser->id,
            'start' => 1,
            'limit' => 1,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)->assertJson(['status' => true]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('php', $data[0]['tag']);
        $this->assertSame(2, $data[0]['post_count']);
    }

    public function test_search_profile_rejects_limit_above_50(): void
    {
        $authUser = $this->createUser();

        $response = $this->postJson('/api/searchProfile', [
            'my_user_id' => $authUser->id,
            'keyword' => 'dev',
            'start' => 0,
            'limit' => 51,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)->assertJson(['status' => false]);
        $this->assertStringContainsString('limit', strtolower((string) $response->json('message')));
    }

    public function test_search_post_rejects_limit_above_100(): void
    {
        $authUser = $this->createUser();

        $response = $this->postJson('/api/searchPost', [
            'user_id' => $authUser->id,
            'keyword' => 'test',
            'start' => 0,
            'limit' => 101,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)->assertJson(['status' => false]);
        $this->assertStringContainsString('limit', strtolower((string) $response->json('message')));
    }

    public function test_search_post_by_interest_requires_integer_interest_id(): void
    {
        $authUser = $this->createUser();

        $response = $this->postJson('/api/searchPostByInterestId', [
            'user_id' => $authUser->id,
            'interest_id' => 'wrong',
            'keyword' => 'test',
            'start' => 0,
            'limit' => 20,
        ], $this->apiHeaders($authUser));

        $response->assertStatus(200)->assertJson(['status' => false]);
        $this->assertStringContainsString('interest', strtolower((string) $response->json('message')));
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
        foreach (['posts', 'interests', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->string('headline')->nullable();
            $table->string('skills')->nullable();
            $table->string('profile')->nullable();
            $table->text('interest_ids')->nullable();
            $table->text('block_user_ids')->nullable();
            $table->integer('followers')->default(0);
            $table->integer('is_verified')->default(0);
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

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('original_post_id')->nullable();
            $table->text('desc')->nullable();
            $table->text('tags')->nullable();
            $table->text('interest_ids')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(array $overrides = []): User
    {
        $user = new User();
        $user->identity = $overrides['identity'] ?? ('identity-' . uniqid());
        $user->username = $overrides['username'] ?? ('user' . uniqid());
        $user->full_name = $overrides['full_name'] ?? 'Search Test User';
        $user->headline = $overrides['headline'] ?? 'Engineer';
        $user->skills = $overrides['skills'] ?? 'php,laravel';
        $user->profile = $overrides['profile'] ?? 'asset/image/default.png';
        $user->interest_ids = $overrides['interest_ids'] ?? '1,2';
        $user->block_user_ids = $overrides['block_user_ids'] ?? '';
        $user->followers = $overrides['followers'] ?? 0;
        $user->is_verified = $overrides['is_verified'] ?? 0;
        $user->is_block = $overrides['is_block'] ?? 0;
        $user->save();

        return $user;
    }

    private function createPost(int $userId, string $tags): void
    {
        DB::table('posts')->insert([
            'user_id' => $userId,
            'desc' => 'Searchable post',
            'tags' => $tags,
            'interest_ids' => '1,2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function apiHeaders(User $user): array
    {
        return [
            'apikey' => self::API_KEY,
            'Authorization' => 'Bearer ' . $user->createToken('search-discovery-test')->plainTextToken,
        ];
    }
}
