<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class SettingsSuperAdminAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_tester_admin_non_ajax_post_to_setting_is_redirected_with_error(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->from('/setting')
            ->post('/setting', ['app_name' => 'ITGA']);

        $response->assertRedirect('/setting');
        $response->assertSessionHas('error', 'Only super administrators can perform this action.');
    }

    public function test_tester_admin_ajax_post_to_setting_is_rejected_as_json(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/setting', ['app_name' => 'ITGA']);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    public function test_super_admin_unknown_setting_payload_uses_safe_fallback_redirect(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'super-admin',
                'user_type' => 1,
            ])
            ->from('/setting')
            ->post('/setting', ['user_password' => 'legacy']);

        $response->assertRedirect('/setting');
        $response->assertSessionHas(
            'error',
            'This action could not be processed. Please reload the settings page and try again.'
        );
    }
}
