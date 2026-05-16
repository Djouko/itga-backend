<?php

namespace Tests\Unit;

use App\Http\Controllers\RoomController;
use Illuminate\Http\Request;
use Tests\TestCase;

class RoomActorEnforcementTest extends TestCase
{
    /**
     * @dataProvider roomManagementActionProvider
     */
    public function test_room_management_actions_reject_authenticated_user_mismatch(string $method, array $payload): void
    {
        $request = Request::create('/api/Room/' . $method, 'POST', $payload);
        $request->attributes->set('authenticated_user_id', 123);

        $response = (new RoomController())->{$method}($request);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('authenticated_user_mismatch', $response->getData(true)['error_code'] ?? null);
    }

    public function test_room_management_action_rejects_missing_auth_context(): void
    {
        $request = Request::create('/api/Room/inviteUserToRoom', 'POST', [
            'my_user_id' => 123,
            'room_id' => 1,
            'user_id' => 2,
        ]);

        $response = (new RoomController())->inviteUserToRoom($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('auth_context_missing', $response->getData(true)['error_code'] ?? null);
    }

    public static function roomManagementActionProvider(): array
    {
        return [
            'edit room' => ['editRoom', ['my_user_id' => 999, 'room_id' => 1]],
            'invite user' => ['inviteUserToRoom', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'accept room request' => ['acceptRoomRequest', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'reject room request' => ['rejectRoomRequest', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'fetch room requests' => ['fetchRoomRequestList', ['my_user_id' => 999, 'room_id' => 1]],
            'fetch room users' => ['fetchRoomUsersList', ['my_user_id' => 999, 'room_id' => 1, 'start' => 0, 'limit' => 10]],
            'remove room user' => ['removeUserFromRoom', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'make room admin' => ['makeRoomAdmin', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'remove room admin' => ['removeAdminFromRoom', ['my_user_id' => 999, 'room_id' => 1, 'user_id' => 2]],
            'fetch room admins' => ['fetchRoomAdmins', ['my_user_id' => 999, 'room_id' => 1]],
        ];
    }
}
