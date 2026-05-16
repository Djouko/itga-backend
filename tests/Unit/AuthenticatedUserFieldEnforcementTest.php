<?php

namespace Tests\Unit;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthenticatedUserFieldEnforcementTest extends TestCase
{
    public function test_enforce_authenticated_user_field_allows_matching_user_id(): void
    {
        $request = Request::create('/api/fake', 'POST', ['user_id' => 7]);
        $request->attributes->set('authenticated_user_id', 7);

        $result = $this->controller()->enforce($request, 'user_id');

        $this->assertNull($result);
        $this->assertSame(7, (int) $request->input('user_id'));
    }

    public function test_enforce_authenticated_user_field_rejects_mismatch(): void
    {
        $request = Request::create('/api/fake', 'POST', ['user_id' => 9]);
        $request->attributes->set('authenticated_user_id', 7);

        $response = $this->controller()->enforce($request, 'user_id');

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame('authenticated_user_mismatch', $payload['error_code'] ?? null);
    }

    public function test_enforce_authenticated_user_field_rejects_missing_auth_context(): void
    {
        $request = Request::create('/api/fake', 'POST', ['user_id' => 7]);

        $response = $this->controller()->enforce($request, 'user_id');

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame('auth_context_missing', $payload['error_code'] ?? null);
    }

    public function test_enforce_authenticated_user_field_injects_authenticated_user_when_missing(): void
    {
        $request = Request::create('/api/fake', 'POST', []);
        $request->attributes->set('authenticated_user_id', 15);

        $result = $this->controller()->enforce($request, 'user_id');

        $this->assertNull($result);
        $this->assertSame(15, (int) $request->input('user_id'));
    }

    private function controller(): object
    {
        return new class extends Controller {
            public function enforce(Request $request, string $field = 'user_id')
            {
                return $this->enforceAuthenticatedUserField($request, $field);
            }
        };
    }
}
