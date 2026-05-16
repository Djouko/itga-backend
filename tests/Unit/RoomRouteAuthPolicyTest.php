<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoomRouteAuthPolicyTest extends TestCase
{
    public function test_room_actor_routes_require_authorize_user(): void
    {
        $protectedRoutes = [
            'createRoom',
            'inviteUserToRoom',
            'joinOrRequestRoom',
            'getInvitationList',
            'acceptInvitation',
            'acceptRoomRequest',
            'rejectInvitation',
            'fetchRoomRequestList',
            'rejectRoomRequest',
            'removeUserFromRoom',
            'makeRoomAdmin',
            'fetchRoomUsersList',
            'reportRoom',
            'leaveThisRoom',
            'fetchRoomDetail',
            'deleteRoom',
            'fetchMyOwnRooms',
            'editRoom',
            'fetchSuggestedRooms',
            'fetchRoomsByInterest',
            'searchUserForInvitation',
            'fetchRandomRooms',
            'fetchRoomAdmins',
            'removeAdminFromRoom',
            'fetchRoomsList',
            'muteUnmuteRoomNotification',
            'fetchRoomsIAmIn',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
        }
    }

    public function test_room_mutation_routes_keep_rate_limits(): void
    {
        $writeThrottledRoutes = [
            'inviteUserToRoom',
            'joinOrRequestRoom',
            'acceptInvitation',
            'acceptRoomRequest',
            'rejectInvitation',
            'rejectRoomRequest',
            'removeUserFromRoom',
            'makeRoomAdmin',
            'reportRoom',
            'leaveThisRoom',
            'deleteRoom',
            'removeAdminFromRoom',
            'muteUnmuteRoomNotification',
        ];

        foreach ($writeThrottledRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:writes');
        }

        foreach (['createRoom', 'editRoom'] as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:uploads');
        }
    }

    private function assertRouteHasMiddleware(string $uri, string $middleware): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri) {
            return trim($route->uri(), '/') === trim('api/' . $uri, '/');
        });

        $this->assertNotNull($route, "Route [{$uri}] should exist.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$uri}] must include middleware [{$middleware}].");
    }
}
