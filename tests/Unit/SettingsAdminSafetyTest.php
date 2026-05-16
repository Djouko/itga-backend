<?php

namespace Tests\Unit;

use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class SettingsAdminSafetyTest extends TestCase
{
    public function test_setting_updates_are_rejected_for_tester_admin_sessions(): void
    {
        $request = $this->requestWithSession(['app_name' => 'ITGA'], 0);

        $response = (new SettingsController())->updateSettings($request);
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['status']);
        $this->assertSame('Only super administrators can perform this action.', $payload['message']);
    }

    public function test_posting_to_setting_without_a_known_payload_redirects_instead_of_method_not_allowed(): void
    {
        $request = $this->requestWithSession(['user_password' => 'old', 'new_password' => 'new'], 1);

        $response = (new SettingsController())->settingPostFallback($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/setting', $response->headers->get('Location'));
    }

    private function requestWithSession(array $payload, int $userType): Request
    {
        $request = Request::create('/setting', 'POST', $payload);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('user_type', $userType);
        $request->setLaravelSession($session);

        return $request;
    }
}
