<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class AdminSettingsEndpointAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_tester_admin_ajax_post_to_update_settings_is_rejected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/updateSettings', ['app_name' => 'ITGA']);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    public function test_tester_admin_browser_post_to_update_settings_is_redirected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->from('/setting')
            ->post('/updateSettings', ['app_name' => 'ITGA']);

        $response->assertRedirect('/setting');
        $response->assertSessionHas('error', 'Only super administrators can perform this action.');
    }

    public function test_tester_admin_ajax_post_to_admob_android_is_rejected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/admobAndroid', ['is_admob_on' => 1]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    public function test_tester_admin_ajax_post_to_admob_ios_is_rejected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/admobiOS', ['is_admob_on' => 1]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }
}
