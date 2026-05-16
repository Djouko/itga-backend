<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModeratorRouteAuthPolicyTest extends TestCase
{
    public function test_moderator_routes_require_authorize_user_and_action_policy(): void
    {
        $protectedRoutes = [
            'Moderator/deletePostByModerator' => 'ensureModeratorAction:delete_post',
            'Moderator/deleteCommentByModerator' => 'ensureModeratorAction:delete_comment',
            'Moderator/deleteRoomByModerator' => 'ensureModeratorAction:delete_room',
            'Moderator/deleteStoryByModerator' => 'ensureModeratorAction:delete_story',
            'Moderator/userBlockByModerator' => 'ensureModeratorAction:block_user',
            'Moderator/deleteReelCommentByModerator' => 'ensureModeratorAction:delete_reel_comment',
            'Moderator/deleteReelByModerator' => 'ensureModeratorAction:delete_reel',
        ];

        foreach ($protectedRoutes as $uri => $actionMiddleware) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
            $this->assertRouteHasMiddleware($uri, 'ensureModerator');
            $this->assertRouteHasMiddleware($uri, 'throttle:moderation');
            $this->assertRouteHasMiddleware($uri, $actionMiddleware);
        }
    }

    public function test_moderation_appeal_routes_require_authenticated_user(): void
    {
        $protectedRoutes = [
            'ModerationAppeal/submit',
            'ModerationAppeal/fetchMyAppeals',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
            $this->assertRouteHasMiddleware($uri, 'throttle:writes');
        }
    }

    private function assertRouteHasMiddleware(string $uri, string $middleware): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri) {
            return trim($route->uri(), '/') === trim('api/' . $uri, '/');
        });

        $this->assertNotNull($route, "Route [{$uri}] should exist.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$uri}] must include middleware [{$middleware}].");
    }
}
