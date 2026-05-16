<?php

namespace Tests\Unit;

use App\Http\Middleware\AuthorizeUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthorizeUserMiddlewareTest extends TestCase
{
    public function test_authorize_user_rejects_missing_token()
    {
        $middleware = $this->middlewareWithToken(null);
        $request = Request::create('/', 'POST');

        $response = $middleware->handle($request, fn () => new JsonResponse(['status' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('token_not_provided', $response->getData(true)['error_code']);
    }

    public function test_authorize_user_rejects_invalid_token()
    {
        $middleware = $this->middlewareWithToken(null);
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
        ]);

        $response = $middleware->handle($request, fn () => new JsonResponse(['status' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('user_not_found', $response->getData(true)['error_code']);
        $this->assertSame('invalid-token', $middleware->seenToken);
    }

    public function test_authorize_user_rejects_blocked_user()
    {
        $user = new User();
        $user->id = 7;
        $user->is_block = 1;
        $accessToken = new AuthorizeUserFakeAccessToken($user);
        $middleware = $this->middlewareWithToken($accessToken);
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_AUTHTOKEN' => 'legacy-token',
        ]);

        $response = $middleware->handle($request, fn () => new JsonResponse(['status' => true]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('account_blocked', $response->getData(true)['error_code']);
    }

    public function test_authorize_user_allows_valid_sanctum_token()
    {
        $user = new User();
        $user->id = 12;
        $user->is_block = 0;
        $accessToken = new AuthorizeUserFakeAccessToken($user);
        $middleware = $this->middlewareWithToken($accessToken);
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        $response = $middleware->handle($request, function (Request $request) use ($user) {
            $this->assertSame($user, $request->user());
            $this->assertSame(12, $request->attributes->get('authenticated_user_id'));
            return new JsonResponse(['status' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($accessToken->saved);
        $this->assertNotNull($accessToken->lastUsedAt);
    }

    private function middlewareWithToken($accessToken)
    {
        return new class($accessToken) extends AuthorizeUser {
            public ?string $seenToken = null;
            private $accessToken;

            public function __construct($accessToken)
            {
                $this->accessToken = $accessToken;
            }

            protected function findAccessToken(string $token)
            {
                $this->seenToken = $token;
                return $this->accessToken;
            }
        };
    }
}

class AuthorizeUserFakeAccessToken
{
    public User $tokenable;
    public $lastUsedAt = null;
    public bool $saved = false;

    public function __construct(User $tokenable)
    {
        $this->tokenable = $tokenable;
    }

    public function forceFill(array $attributes): self
    {
        $this->lastUsedAt = $attributes['last_used_at'] ?? null;
        return $this;
    }

    public function save(): bool
    {
        $this->saved = true;
        return true;
    }
}
