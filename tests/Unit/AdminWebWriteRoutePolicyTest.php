<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminWebWriteRoutePolicyTest extends TestCase
{
    public function test_admin_write_and_moderation_routes_require_super_admin(): void
    {
        $protectedRoutes = [
            'sendNotification',
            'updateNotification',
            'repeatNotification',
            'deleteNotification',
            'changePassword',
            'deleteThisRoom',
            'addInterest',
            'updateInterest',
            'deleteInterest/{id}',
            'updatePrivacy',
            'addContentForm',
            'addTermsForm',
            'updateTerms',
            'deleteReport',
            'deleteReportWithRoom',
            'updatePrivateStatus',
            'updateJoinRequestStatus',
            'approvedProfileVerification/{id}',
            'rejectProfileVerification/{id}',
            'adminModerateJobWeb',
            'adminToggleSuspendCompanyWeb',
            'addDocumentType',
            'updateDocumentType/{id}',
            'deleteDocumentType/{id}',
            'addreportReason',
            'updateReportReason/{id}',
            'deleteReportReasonType',
            'changeAppName',
            'deletePostReport',
            'deletePost',
            'updatePostRestrictionStatus',
            'deleteCommentFromAdmin',
            'deleteReelCommentFromAdmin',
            'deleteReelByAdmin',
            'deleteReelReport',
            'deleteReelFromReport',
            'addCategory',
            'updateCategory',
            'deleteCategory',
            'addMusic',
            'updateMusic',
            'deleteMusic',
            'deleteUserReport',
            'blockUserFromReport',
            'blockUserByAdmin/{id}',
            'unblockUserByAdmin/{id}',
            'verifyUser',
            'deletePostFromUserPostTable',
            'editProfileFormWeb',
            'updateModeratorStatus',
            'deleteAvatarFromUserDetail',
            'deleteProfileFromUserDetail',
            'addFAQsType',
            'updateFAQsType',
            'deleteFAQsType',
            'addFAQs',
            'updateFAQs',
            'deleteFAQs',
            'deleteStoryFromAdmin',
            'addUsernameRestrict',
            'updateUsernameRestrict',
            'deleteUsernameRestrictions',
            'androidDeepLinking',
            'iOSDeepLinking',
            'admobAndroid',
            'admobiOS',
            'addFakeData',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'POST', 'checkLogin');
            $this->assertRouteHasMiddleware($uri, 'POST', 'superAdmin');
        }
    }

    private function assertRouteHasMiddleware(string $uri, string $method, string $middleware): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri, $method) {
            return trim($route->uri(), '/') === trim($uri, '/')
                && in_array(strtoupper($method), $route->methods(), true);
        });

        $this->assertNotNull($route, "Route [{$method} {$uri}] should exist.");
        $this->assertContains(
            $middleware,
            $route->gatherMiddleware(),
            "Route [{$method} {$uri}] must include middleware [{$middleware}]."
        );
    }
}
