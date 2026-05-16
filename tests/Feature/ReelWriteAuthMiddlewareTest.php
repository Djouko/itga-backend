<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReelWriteAuthMiddlewareTest extends TestCase
{
    private const API_KEY = 'reel-write-auth-key';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('API_SECRET_KEY=' . self::API_KEY);
        $_ENV['API_SECRET_KEY'] = self::API_KEY;
        $_SERVER['API_SECRET_KEY'] = self::API_KEY;
    }

    public function test_like_dislike_reel_requires_authorized_user_token(): void
    {
        $response = $this->postJson('/api/likeDislikeReel', [
            'user_id' => 1,
            'reel_id' => 1,
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(401)->assertJson([
            'status' => false,
            'message' => 'Unauthorized Access',
            'error_code' => 'token_not_provided',
        ]);
    }

    public function test_edit_reel_comment_requires_authorized_user_token(): void
    {
        $response = $this->postJson('/api/editReelComment', [
            'my_user_id' => 1,
            'comment_id' => 1,
            'description' => 'Edited text',
        ], [
            'apikey' => self::API_KEY,
        ]);

        $response->assertStatus(401)->assertJson([
            'status' => false,
            'message' => 'Unauthorized Access',
            'error_code' => 'token_not_provided',
        ]);
    }
}

