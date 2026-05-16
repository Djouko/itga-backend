<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureModerator;
use App\Models\Constants;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureModeratorMiddlewareTest extends TestCase
{
    public function test_moderator_is_allowed(): void
    {
        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');
        $request->setUserResolver(fn () => $this->makeUser(Constants::moderator));

        $response = (new EnsureModerator())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
    }

    public function test_non_moderator_is_rejected(): void
    {
        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');
        $request->setUserResolver(fn () => $this->makeUser(Constants::moderator_false));

        $response = (new EnsureModerator())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame('User is not a Moderator', $response->getData(true)['message']);
    }

    public function test_missing_user_is_rejected(): void
    {
        $request = Request::create('/api/Moderator/deletePostByModerator', 'POST');

        $response = (new EnsureModerator())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame('User not found', $response->getData(true)['message']);
    }

    private function makeUser(int $isModerator): User
    {
        $user = new User();
        $user->id = 1;
        $user->is_moderator = $isModerator;

        return $user;
    }
}
