<?php

namespace Tests\Unit;

use App\Http\Middleware\CheckLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CheckLoginMiddlewareTest extends TestCase
{
    public function test_authenticated_session_is_allowed(): void
    {
        $request = $this->requestWithSessionUser('admin-user');
        Session::put('user_name', 'admin-user');

        $response = (new CheckLogin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
    }

    public function test_unauthenticated_ajax_request_gets_json_401(): void
    {
        $request = $this->requestWithoutSession(true);
        Session::forget('user_name');

        $response = (new CheckLogin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $payload = $response->getData(true);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($payload['status']);
        $this->assertSame('Authentication required.', $payload['message']);
    }

    public function test_unauthenticated_browser_request_redirects_to_login(): void
    {
        $request = $this->requestWithoutSession(false);
        Session::forget('user_name');

        $response = (new CheckLogin())->handle(
            $request,
            fn () => new JsonResponse(['status' => true], 200)
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
    }

    private function requestWithSessionUser(string $username): Request
    {
        $request = Request::create('/admin-read', 'POST');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('user_name', $username);
        $request->setLaravelSession($session);

        return $request;
    }

    private function requestWithoutSession(bool $ajax): Request
    {
        $request = Request::create('/admin-read', 'POST');
        if ($ajax) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        $session = new Store('test', new ArraySessionHandler(120));
        $request->setLaravelSession($session);

        return $request;
    }
}
