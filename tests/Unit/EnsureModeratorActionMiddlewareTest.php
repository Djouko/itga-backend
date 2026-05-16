<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureModeratorAction;
use App\Services\ModerationActionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureModeratorActionMiddlewareTest extends TestCase
{
    public function test_known_enabled_action_is_allowed_and_exposed_on_request(): void
    {
        config()->set('moderation.actions', ['delete_post']);
        config()->set('moderation.enabled_actions', ['delete_post']);

        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');

        $response = (new EnsureModeratorAction(new ModerationActionPolicy()))->handle(
            $request,
            function (Request $nextRequest) {
                return new JsonResponse([
                    'status' => true,
                    'action' => $nextRequest->attributes->get('moderation_action_key'),
                ], 200);
            },
            'delete_post'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
        $this->assertSame('delete_post', $response->getData(true)['action']);
    }

    public function test_unknown_action_is_rejected(): void
    {
        config()->set('moderation.actions', ['delete_post']);
        config()->set('moderation.enabled_actions', ['*']);

        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');

        $response = (new EnsureModeratorAction(new ModerationActionPolicy()))->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200),
            'unknown_action'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame('Unsupported moderation action policy.', $response->getData(true)['message']);
    }

    public function test_disabled_action_is_rejected(): void
    {
        config()->set('moderation.actions', ['delete_post', 'delete_comment']);
        config()->set('moderation.enabled_actions', ['delete_comment']);

        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');

        $response = (new EnsureModeratorAction(new ModerationActionPolicy()))->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200),
            'delete_post'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame('This moderation action is currently disabled.', $response->getData(true)['message']);
    }
}
