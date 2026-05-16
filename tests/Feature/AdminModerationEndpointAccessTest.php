<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class AdminModerationEndpointAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_tester_admin_ajax_post_to_delete_post_is_rejected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/deletePost', ['id' => 1]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    public function test_tester_admin_browser_post_to_delete_room_is_redirected_with_error(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->from('/rooms')
            ->post('/deleteThisRoom', ['room_id' => 1]);

        $response->assertRedirect('/rooms');
        $response->assertSessionHas('error', 'Only super administrators can perform this action.');
    }

    public function test_tester_admin_ajax_post_to_block_user_is_rejected(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson('/blockUserByAdmin/1');

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    public function test_unauthenticated_ajax_post_to_admin_write_route_gets_json_401(): void
    {
        $response = $this->postJson('/deletePost', ['id' => 1]);

        $response
            ->assertStatus(401)
            ->assertJson([
                'status' => false,
                'message' => 'Authentication required.',
            ]);
    }
}
