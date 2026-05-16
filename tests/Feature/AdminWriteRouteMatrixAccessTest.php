<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class AdminWriteRouteMatrixAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    /**
     * @dataProvider adminWriteRoutesProvider
     */
    public function test_tester_admin_ajax_requests_are_rejected_on_sensitive_write_routes(
        string $label,
        string $uri,
        array $payload = []
    ): void {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->postJson($uri, $payload);

        $response
            ->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only super administrators can perform this action.',
            ]);
    }

    /**
     * @dataProvider adminWriteRoutesBrowserProvider
     */
    public function test_tester_admin_browser_requests_are_redirected_on_sensitive_write_routes(
        string $label,
        string $uri,
        string $from,
        array $payload = []
    ): void {
        $response = $this
            ->withSession([
                'user_name' => 'tester-admin',
                'user_type' => 0,
            ])
            ->from($from)
            ->post($uri, $payload);

        $response->assertRedirect($from);
        $response->assertSessionHas('error', 'Only super administrators can perform this action.');
    }

    public function adminWriteRoutesProvider(): array
    {
        $cases = [];

        foreach ($this->adminWriteRouteCases() as $case) {
            $cases[] = [$case['label'], $case['uri'], $case['payload']];
        }

        return $cases;
    }

    public function adminWriteRoutesBrowserProvider(): array
    {
        $cases = [];

        foreach ($this->adminWriteRouteCases() as $case) {
            $cases[] = [$case['label'], $case['uri'], $case['from'], $case['payload']];
        }

        return $cases;
    }

    /**
     * @return array<int, array{label: string, uri: string, from: string, payload: array}>
     */
    private function adminWriteRouteCases(): array
    {
        return [
            [
                'label' => 'POST /setting fallback',
                'uri' => '/setting',
                'from' => '/setting',
                'payload' => ['app_name' => 'ITGA'],
            ],
            [
                'label' => 'POST /updateSettings',
                'uri' => '/updateSettings',
                'from' => '/setting',
                'payload' => ['app_name' => 'ITGA'],
            ],
            [
                'label' => 'POST /sendNotification',
                'uri' => '/sendNotification',
                'from' => '/notification',
                'payload' => ['title' => 'Notice'],
            ],
            [
                'label' => 'POST /updateNotification',
                'uri' => '/updateNotification',
                'from' => '/notification',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /repeatNotification',
                'uri' => '/repeatNotification',
                'from' => '/notification',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteNotification',
                'uri' => '/deleteNotification',
                'from' => '/notification',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /changePassword',
                'uri' => '/changePassword',
                'from' => '/notification',
                'payload' => ['user_password' => 'Secret123!'],
            ],
            [
                'label' => 'POST /deleteThisRoom',
                'uri' => '/deleteThisRoom',
                'from' => '/rooms',
                'payload' => ['room_id' => 1],
            ],
            [
                'label' => 'POST /addInterest',
                'uri' => '/addInterest',
                'from' => '/interests',
                'payload' => ['interest_name' => 'Tech'],
            ],
            [
                'label' => 'POST /updateInterest',
                'uri' => '/updateInterest',
                'from' => '/interests',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteInterest/{id}',
                'uri' => '/deleteInterest/1',
                'from' => '/interests',
                'payload' => [],
            ],
            [
                'label' => 'POST /updatePrivacy',
                'uri' => '/updatePrivacy',
                'from' => '/viewPrivacy',
                'payload' => ['privacy_text' => 'Privacy'],
            ],
            [
                'label' => 'POST /addContentForm',
                'uri' => '/addContentForm',
                'from' => '/viewTerms',
                'payload' => ['content' => 'Terms content'],
            ],
            [
                'label' => 'POST /addTermsForm',
                'uri' => '/addTermsForm',
                'from' => '/viewTerms',
                'payload' => ['content' => 'Terms'],
            ],
            [
                'label' => 'POST /updateTerms',
                'uri' => '/updateTerms',
                'from' => '/viewTerms',
                'payload' => ['content' => 'Terms'],
            ],
            [
                'label' => 'POST /deleteReport',
                'uri' => '/deleteReport',
                'from' => '/reports',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteReportWithRoom',
                'uri' => '/deleteReportWithRoom',
                'from' => '/reports',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /updatePrivateStatus',
                'uri' => '/updatePrivateStatus',
                'from' => '/reports',
                'payload' => ['room_id' => 1],
            ],
            [
                'label' => 'POST /updateJoinRequestStatus',
                'uri' => '/updateJoinRequestStatus',
                'from' => '/reports',
                'payload' => ['room_id' => 1],
            ],
            [
                'label' => 'POST /approvedProfileVerification/{id}',
                'uri' => '/approvedProfileVerification/1',
                'from' => '/verificationRequests',
                'payload' => [],
            ],
            [
                'label' => 'POST /rejectProfileVerification/{id}',
                'uri' => '/rejectProfileVerification/1',
                'from' => '/verificationRequests',
                'payload' => [],
            ],
            [
                'label' => 'POST /adminModerateJobWeb',
                'uri' => '/adminModerateJobWeb',
                'from' => '/adminJobs',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /adminToggleSuspendCompanyWeb',
                'uri' => '/adminToggleSuspendCompanyWeb',
                'from' => '/adminCompanies',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addDocumentType',
                'uri' => '/addDocumentType',
                'from' => '/setting',
                'payload' => ['type_name' => 'PDF'],
            ],
            [
                'label' => 'POST /updateDocumentType/{id}',
                'uri' => '/updateDocumentType/1',
                'from' => '/setting',
                'payload' => [],
            ],
            [
                'label' => 'POST /deleteDocumentType/{id}',
                'uri' => '/deleteDocumentType/1',
                'from' => '/setting',
                'payload' => [],
            ],
            [
                'label' => 'POST /addreportReason',
                'uri' => '/addreportReason',
                'from' => '/setting',
                'payload' => ['reason' => 'Spam'],
            ],
            [
                'label' => 'POST /updateReportReason/{id}',
                'uri' => '/updateReportReason/1',
                'from' => '/setting',
                'payload' => [],
            ],
            [
                'label' => 'POST /deleteReportReasonType',
                'uri' => '/deleteReportReasonType',
                'from' => '/setting',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /changeAppName',
                'uri' => '/changeAppName',
                'from' => '/setting',
                'payload' => ['app_name' => 'ITGA'],
            ],
            [
                'label' => 'POST /deletePostReport',
                'uri' => '/deletePostReport',
                'from' => '/viewPosts',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deletePost',
                'uri' => '/deletePost',
                'from' => '/viewPosts',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /updatePostRestrictionStatus',
                'uri' => '/updatePostRestrictionStatus',
                'from' => '/viewPosts',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteCommentFromAdmin',
                'uri' => '/deleteCommentFromAdmin',
                'from' => '/viewPosts',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteReelCommentFromAdmin',
                'uri' => '/deleteReelCommentFromAdmin',
                'from' => '/viewReels',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteReelByAdmin',
                'uri' => '/deleteReelByAdmin',
                'from' => '/viewReels',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteReelReport',
                'uri' => '/deleteReelReport',
                'from' => '/viewReels',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteReelFromReport',
                'uri' => '/deleteReelFromReport',
                'from' => '/viewReels',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addCategory',
                'uri' => '/addCategory',
                'from' => '/musics',
                'payload' => ['name' => 'General'],
            ],
            [
                'label' => 'POST /updateCategory',
                'uri' => '/updateCategory',
                'from' => '/musics',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteCategory',
                'uri' => '/deleteCategory',
                'from' => '/musics',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addMusic',
                'uri' => '/addMusic',
                'from' => '/musics',
                'payload' => ['name' => 'Track'],
            ],
            [
                'label' => 'POST /updateMusic',
                'uri' => '/updateMusic',
                'from' => '/musics',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteMusic',
                'uri' => '/deleteMusic',
                'from' => '/musics',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteUserReport',
                'uri' => '/deleteUserReport',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /blockUserFromReport',
                'uri' => '/blockUserFromReport',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /blockUserByAdmin/{id}',
                'uri' => '/blockUserByAdmin/1',
                'from' => '/users',
                'payload' => [],
            ],
            [
                'label' => 'POST /unblockUserByAdmin/{id}',
                'uri' => '/unblockUserByAdmin/1',
                'from' => '/users',
                'payload' => [],
            ],
            [
                'label' => 'POST /verifyUser',
                'uri' => '/verifyUser',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deletePostFromUserPostTable',
                'uri' => '/deletePostFromUserPostTable',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /editProfileFormWeb',
                'uri' => '/editProfileFormWeb',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /updateModeratorStatus',
                'uri' => '/updateModeratorStatus',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteAvatarFromUserDetail',
                'uri' => '/deleteAvatarFromUserDetail',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteProfileFromUserDetail',
                'uri' => '/deleteProfileFromUserDetail',
                'from' => '/users',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addFAQsType',
                'uri' => '/addFAQsType',
                'from' => '/faqs',
                'payload' => ['name' => 'General'],
            ],
            [
                'label' => 'POST /updateFAQsType',
                'uri' => '/updateFAQsType',
                'from' => '/faqs',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteFAQsType',
                'uri' => '/deleteFAQsType',
                'from' => '/faqs',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addFAQs',
                'uri' => '/addFAQs',
                'from' => '/faqs',
                'payload' => ['question' => 'Q', 'answer' => 'A'],
            ],
            [
                'label' => 'POST /updateFAQs',
                'uri' => '/updateFAQs',
                'from' => '/faqs',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteFAQs',
                'uri' => '/deleteFAQs',
                'from' => '/faqs',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteStoryFromAdmin',
                'uri' => '/deleteStoryFromAdmin',
                'from' => '/viewStories',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /addUsernameRestrict',
                'uri' => '/addUsernameRestrict',
                'from' => '/restrictions',
                'payload' => ['username' => 'blocked-name'],
            ],
            [
                'label' => 'POST /updateUsernameRestrict',
                'uri' => '/updateUsernameRestrict',
                'from' => '/restrictions',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /deleteUsernameRestrictions',
                'uri' => '/deleteUsernameRestrictions',
                'from' => '/restrictions',
                'payload' => ['id' => 1],
            ],
            [
                'label' => 'POST /admobAndroid',
                'uri' => '/admobAndroid',
                'from' => '/admob',
                'payload' => ['is_admob_on' => 1],
            ],
            [
                'label' => 'POST /admobiOS',
                'uri' => '/admobiOS',
                'from' => '/admob',
                'payload' => ['is_admob_on' => 1],
            ],
            [
                'label' => 'POST /addFakeData',
                'uri' => '/addFakeData',
                'from' => '/index',
                'payload' => ['count' => 1],
            ],
        ];
    }
}
