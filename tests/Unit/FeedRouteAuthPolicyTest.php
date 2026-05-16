<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeedRouteAuthPolicyTest extends TestCase
{
    public function test_feed_actor_routes_require_authorize_user(): void
    {
        $protectedRoutes = [
            'addPost',
            'editPost',
            'repostPost',
            'fetchPosts',
            'addComment',
            'fetchComments',
            'fetchReplies',
            'editComment',
            'deleteComment',
            'likePost',
            'dislikePost',
            'reportPost',
            'deleteMyPost',
            'fetchPostByPostId',
            'fetchPostsByHashtag',
            'createStory',
            'viewStory',
            'fetchStory',
            'deleteStory',
            'fetchUsersWhoLikedPost',
            'searchHashtag',
            'searchPost',
            'likeDislikeComment',
            'searchPostByInterestId',
            'fetchSavedPosts',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
        }
    }

    public function test_feed_mutation_routes_keep_rate_limits(): void
    {
        $writeThrottledRoutes = [
            'editPost',
            'repostPost',
            'addComment',
            'editComment',
            'deleteComment',
            'likePost',
            'dislikePost',
            'reportPost',
            'deleteMyPost',
            'deleteStory',
            'likeDislikeComment',
        ];

        foreach ($writeThrottledRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:writes');
        }

        foreach (['addPost', 'createStory'] as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:uploads');
        }

        $this->assertRouteHasMiddleware('viewStory', 'throttle:engagement');
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
