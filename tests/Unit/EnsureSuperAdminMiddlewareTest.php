<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class EnsureSuperAdminMiddlewareTest extends TestCase
{
    public function test_super_admin_sessions_are_allowed(): void
    {
        $request = $this->requestWithUserType(1);

        $response = (new EnsureSuperAdmin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
    }

    public function test_tester_admin_ajax_sessions_are_rejected_as_json(): void
    {
        $request = $this->requestWithUserType(0, true);

        $response = (new EnsureSuperAdmin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['status']);
        $this->assertSame('Only super administrators can perform this action.', $payload['message']);
    }

    public function test_tester_admin_browser_sessions_are_redirected_with_error_message(): void
    {
        $request = $this->requestWithUserType(0, false, 'http://localhost/setting');

        $response = (new EnsureSuperAdmin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
    }

    private function requestWithUserType(int $userType, bool $ajax = false, ?string $referer = null): Request
    {
        $request = Request::create('/admin-write', 'POST');

        if ($ajax) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
        if ($referer) {
            $request->headers->set('referer', $referer);
        }

        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('user_type', $userType);
        $request->setLaravelSession($session);

        return $request;
    }
}
