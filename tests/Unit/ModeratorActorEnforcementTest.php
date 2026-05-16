<?php

namespace Tests\Unit;

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Tests\TestCase;

class ModeratorActorEnforcementTest extends TestCase
{
    /**
     * @dataProvider moderatorActionProvider
     */
    public function test_moderator_actions_reject_authenticated_user_mismatch(string $method, array $payload): void
    {
        $request = Request::create('/api/Moderator/' . $method, 'POST', $payload);
        $request->attributes->set('authenticated_user_id', 123);

        $response = (new UserController())->{$method}($request);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('authenticated_user_mismatch', $response->getData(true)['error_code'] ?? null);
    }

    public function test_moderator_action_rejects_missing_auth_context(): void
    {
        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST', [
            'user_id' => 123,
            'post_id' => 1,
        ]);

        $response = (new UserController())->deletePostByModerator($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('auth_context_missing', $response->getData(true)['error_code'] ?? null);
    }

    public function test_delete_post_by_moderator_requires_post_id(): void
    {
        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST', [
            'user_id' => 123,
        ]);
        $request->attributes->set('authenticated_user_id', 123);

        $response = (new UserController())->deletePostByModerator($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status'] ?? true);
    }

    public function test_user_block_by_moderator_requires_target_user_id(): void
    {
        $request = Request::create('/api/Moderator/userBlockByModerator', 'POST', [
            'user_id' => 123,
        ]);
        $request->attributes->set('authenticated_user_id', 123);

        $response = (new UserController())->userBlockByModerator($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status'] ?? true);
    }

    public static function moderatorActionProvider(): array
    {
        return [
            'delete post' => ['deletePostByModerator', ['user_id' => 999, 'post_id' => 1]],
            'delete comment' => ['deleteCommentByModerator', ['user_id' => 999, 'comment_id' => 1]],
            'delete room' => ['deleteRoomByModerator', ['user_id' => 999, 'room_id' => 1]],
            'delete story' => ['deleteStoryByModerator', ['user_id' => 999, 'story_id' => 1]],
            'block user' => ['userBlockByModerator', ['user_id' => 999, 'to_user_id' => 2]],
            'delete reel comment' => ['deleteReelCommentByModerator', ['user_id' => 999, 'reel_comment_id' => 1]],
            'delete reel' => ['deleteReelByModerator', ['user_id' => 999, 'reel_id' => 1]],
        ];
    }
}
