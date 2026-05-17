<?php 

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\FAQsController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileVerificationController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\RestrictionController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShareLinkPageController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('s/{shareLinkPage}', [ShareLinkPageController::class, 'shareLinkPage'])->name('shareLinkPage');

Route::get('/.well-known/apple-app-site-association', function () {
    $file = public_path('asset/apple-app-site-association');
    if (!File::exists($file)) {
        abort(404, 'File not found');
    }
    return Response::file($file, [
        'Content-Type' => 'application/json', // Force JSON
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
});

Route::get('/.well-known/assetlinks.json', function () {
    $file = public_path('asset/assetlinks.json');
    if (!File::exists($file)) {
        abort(404, 'File not found');
    }
    return Response::file($file, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
});

Route::get('/', [LoginController::class, 'login'])->name('/');
Route::post('loginForm', [LoginController::class, 'checklogin'])->middleware(['throttle:5,1'])->name('loginForm');
Route::post('forgotPasswordForm', [LoginController::class, 'forgotPasswordForm'])->middleware(['throttle:3,10'])->name('forgotPasswordForm');
Route::get('logout', [LoginController::class, 'logout'])->middleware(['checkLogin'])->name('logout');

Route::get('index', [SettingsController::class, 'index'])->middleware(['checkLogin'])->name('index');
Route::get('fetchAllChartData', [SettingsController::class, 'fetchAllChartData'])->middleware(['checkLogin'])->name('fetchAllChartData');

// Notification
Route::get('notification', [AdminController::class, 'notification'])->middleware(['checkLogin'])->name('notification');
Route::post('sendNotification', [AdminController::class, 'sendNotification'])->middleware(['checkLogin', 'superAdmin'])->name('sendNotification');
Route::post('adminNotificationList', [AdminController::class, 'adminNotificationList'])->middleware(['checkLogin'])->name('adminNotificationList');
Route::post('updateNotification', [AdminController::class, 'updateNotification'])->middleware(['checkLogin', 'superAdmin'])->name('updateNotification');
Route::post('repeatNotification', [AdminController::class, 'repeatNotification'])->middleware(['checkLogin', 'superAdmin'])->name('repeatNotification');
Route::post('deleteNotification', [AdminController::class, 'deleteNotification'])->middleware(['checkLogin', 'superAdmin'])->name('deleteNotification');
Route::post('changePassword', [AdminController::class, 'changePassword'])->middleware(['checkLogin', 'superAdmin'])->name('changePassword');

Route::get('rooms', [RoomController::class, 'rooms'])->middleware(['checkLogin'])->name('rooms');
Route::post('roomsListWeb', [RoomController::class, 'roomsListWeb'])->middleware(['checkLogin'])->name('roomsListWeb');
Route::post('deleteThisRoom', [RoomController::class, 'deleteThisRoom'])->middleware(['checkLogin', 'superAdmin'])->name('deleteThisRoom');

Route::get('interests', [InterestController::class, 'interests'])->middleware(['checkLogin'])->name('interests');
Route::post('addInterest', [InterestController::class, 'addInterest'])->middleware(['checkLogin', 'superAdmin'])->name('addInterest');
Route::post('interestList', [InterestController::class, 'interestList'])->middleware(['checkLogin'])->name('interestList');
Route::post('updateInterest', [InterestController::class, 'updateInterest'])->middleware(['checkLogin', 'superAdmin'])->name('updateInterest');
Route::post('deleteInterest/{id}', [InterestController::class, 'deleteInterest'])->middleware(['checkLogin', 'superAdmin'])->name('deleteInterest');

// Pages Routes

Route::get('deleteStoryFromWeb', [PostController::class, 'deleteStoryFromWeb'])->middleware(['throttle:6,1'])->name('deleteStoryFromWeb');

Route::get('viewPrivacy', [PagesController::class, 'viewPrivacy'])->middleware(['checkLogin'])->name('viewPrivacy');
Route::post('updatePrivacy', [PagesController::class, 'updatePrivacy'])->middleware(['checkLogin', 'superAdmin'])->name('updatePrivacy');
Route::post('addContentForm', [PagesController::class, 'addContentForm'])->middleware(['checkLogin', 'superAdmin'])->name('addContentForm');
Route::post('addTermsForm', [PagesController::class, 'addTermsForm'])->middleware(['checkLogin', 'superAdmin'])->name('addTermsForm');
Route::post('updateTerms', [PagesController::class, 'updateTerms'])->middleware(['checkLogin', 'superAdmin'])->name('updateTerms');
Route::get('viewTerms', [PagesController::class, 'viewTerms'])->middleware(['checkLogin'])->name('viewTerms');
Route::get('privacyPolicy', [PagesController::class, 'privacyPolicy'])->name('privacyPolicy');
Route::get('termsOfUse', [PagesController::class, 'termsOfUse'])->name('termsOfUse');

Route::get('reports', [RoomController::class, 'reports'])->middleware(['checkLogin'])->name('reports');
Route::post('reportList', [RoomController::class, 'reportList'])->middleware(['checkLogin'])->name('reportList');
Route::post('deleteReport', [RoomController::class, 'deleteReport'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReport');
Route::post('deleteReportWithRoom', [RoomController::class, 'deleteReportWithRoom'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReportWithRoom');
Route::post('updatePrivateStatus', [RoomController::class, 'updatePrivateStatus'])->middleware(['checkLogin', 'superAdmin'])->name('updatePrivateStatus');
Route::post('updateJoinRequestStatus', [RoomController::class, 'updateJoinRequestStatus'])->middleware(['checkLogin', 'superAdmin'])->name('updateJoinRequestStatus');
Route::get('roomDetails/{id}', [RoomController::class, 'roomDetails'])->middleware(['checkLogin'])->name('roomDetails');
Route::post('allRoomUsersListTableWeb', [RoomController::class, 'allRoomUsersListTableWeb'])->middleware(['checkLogin'])->name('allRoomUsersListTableWeb');
Route::post('roomMembersListTableWeb', [RoomController::class, 'roomMembersListTableWeb'])->middleware(['checkLogin'])->name('roomMembersListTableWeb');
Route::post('roomCoAdminTableWeb', [RoomController::class, 'roomCoAdminTableWeb'])->middleware(['checkLogin'])->name('roomCoAdminTableWeb');
Route::post('userRoomsOwnTable', [RoomController::class, 'userRoomsOwnTable'])->middleware(['checkLogin'])->name('userRoomsOwnTable');
Route::post('userRoomInTable', [RoomController::class, 'userRoomInTable'])->middleware(['checkLogin'])->name('userRoomInTable');

Route::get('verificationRequests', [ProfileVerificationController::class, 'verificationRequests'])->middleware(['checkLogin'])->name('verificationRequests');
Route::post('profileVerificationList', [ProfileVerificationController::class, 'profileVerificationList'])->middleware(['checkLogin'])->name('profileVerificationList');
Route::post('approvedProfileVerification/{id}', [ProfileVerificationController::class, 'approvedProfileVerification'])->middleware(['checkLogin', 'superAdmin'])->name('approvedProfileVerification');
Route::post('rejectProfileVerification/{id}', [ProfileVerificationController::class, 'rejectProfileVerification'])->middleware(['checkLogin', 'superAdmin'])->name('rejectProfileVerification');

Route::get('adminJobs', [ApplicationController::class, 'adminJobs'])->middleware(['checkLogin'])->name('adminJobs');
Route::post('adminModerateJobWeb', [ApplicationController::class, 'adminModerateJobWeb'])->middleware(['checkLogin', 'superAdmin'])->name('adminModerateJobWeb');
Route::get('adminCompanies', [ApplicationController::class, 'adminCompanies'])->middleware(['checkLogin'])->name('adminCompanies');
Route::post('adminToggleSuspendCompanyWeb', [ApplicationController::class, 'adminToggleSuspendCompanyWeb'])->middleware(['checkLogin', 'superAdmin'])->name('adminToggleSuspendCompanyWeb');
Route::post('adminToggleVerifyCompanyWeb', [ApplicationController::class, 'adminToggleVerifyCompanyWeb'])->middleware(['checkLogin', 'superAdmin'])->name('adminToggleVerifyCompanyWeb');
Route::get('moderationAuditLogs', [UserController::class, 'moderationAuditLogs'])->middleware(['checkLogin'])->name('moderationAuditLogs');

Route::get('setting', [SettingsController::class, 'settingView'])->middleware(['checkLogin'])->name('setting');
Route::post('setting', [SettingsController::class, 'settingPostFallback'])->middleware(['checkLogin', 'superAdmin'])->name('settingPostFallback');
Route::post('addDocumentType', [SettingsController::class, 'addDocumentType'])->middleware(['checkLogin', 'superAdmin'])->name('addDocumentType');
Route::post('documentTypeList', [SettingsController::class, 'documentTypeList'])->middleware(['checkLogin'])->name('documentTypeList');
Route::post('updateDocumentType/{id}', [SettingsController::class, 'updateDocumentType'])->middleware(['checkLogin', 'superAdmin'])->name('updateDocumentType');
Route::post('deleteDocumentType/{id}', [SettingsController::class, 'deleteDocumentType'])->middleware(['checkLogin', 'superAdmin'])->name('deleteDocumentType');
Route::post('updateSettings', [SettingsController::class, 'updateSettings'])->middleware(['checkLogin', 'superAdmin'])->name('updateSettings');
Route::post('androidDeepLinking', [SettingsController::class, 'androidDeepLinking'])->middleware(['checkLogin', 'superAdmin'])->name('androidDeepLinking');
Route::post('iOSDeepLinking', [SettingsController::class, 'iOSDeepLinking'])->middleware(['checkLogin', 'superAdmin'])->name('iOSDeepLinking');



Route::post('addreportReason', [SettingsController::class, 'addreportReason'])->middleware(['checkLogin', 'superAdmin'])->name('addreportReason');
Route::post('reportReasonList', [SettingsController::class, 'reportReasonList'])->middleware(['checkLogin'])->name('reportReasonList');
Route::post('updateReportReason/{id}', [SettingsController::class, 'updateReportReason'])->middleware(['checkLogin', 'superAdmin'])->name('updateReportReason');
Route::post('deleteReportReasonType', [SettingsController::class, 'deleteReportReasonType'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReportReasonType');

Route::post('changeAppName', [SettingsController::class, 'changeAppName'])->middleware(['checkLogin', 'superAdmin'])->name('changeAppName');

Route::get('viewPosts', [PostController::class, 'viewPosts'])->middleware(['checkLogin'])->name('viewPosts');
Route::post('reportPostList', [PostController::class, 'reportPostList'])->middleware(['checkLogin'])->name('reportPostList');
Route::post('deletePostReport', [PostController::class, 'deletePostReport'])->middleware(['checkLogin', 'superAdmin'])->name('deletePostReport');
Route::post('deletePost', [PostController::class, 'deletePost'])->middleware(['checkLogin', 'superAdmin'])->name('deletePost');
Route::post('allPostsList', [PostController::class, 'allPostsList'])->middleware(['checkLogin'])->name('allPostsList');
Route::post('updatePostRestrictionStatus', [PostController::class, 'updatePostRestrictionStatus'])->middleware(['checkLogin', 'superAdmin'])->name('updatePostRestrictionStatus');
Route::get('fetchPostComment', [PostController::class, 'fetchPostComment'])->middleware(['checkLogin'])->name('fetchPostComment');
Route::post('deleteCommentFromAdmin', [PostController::class, 'deleteCommentFromAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('deleteCommentFromAdmin');

Route::get('viewReels', [ReelController::class, 'viewReels'])->middleware(['checkLogin'])->name('viewReels');
Route::post('reelList', [ReelController::class, 'reelList'])->middleware(['checkLogin'])->name('reelList');
Route::get('fetchCommentsInReelModal', [ReelController::class, 'fetchCommentsInReelModal'])->middleware(['checkLogin'])->name('fetchCommentsInReelModal');
Route::post('deleteReelCommentFromAdmin', [ReelController::class, 'deleteReelCommentFromAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReelCommentFromAdmin');
Route::post('deleteReelByAdmin', [ReelController::class, 'deleteReelByAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReelByAdmin');
Route::post('userReelList', [ReelController::class, 'userReelList'])->middleware(['checkLogin'])->name('userReelList');
Route::post('reelReportList', [ReelController::class, 'reelReportList'])->middleware(['checkLogin'])->name('reelReportList');
Route::post('deleteReelReport', [ReelController::class, 'deleteReelReport'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReelReport');
Route::post('deleteReelFromReport', [ReelController::class, 'deleteReelFromReport'])->middleware(['checkLogin', 'superAdmin'])->name('deleteReelFromReport');

Route::get('musics', [ReelController::class, 'musics'])->middleware(['checkLogin'])->name('musics');
Route::post('categoryList', [ReelController::class, 'categoryList'])->middleware(['checkLogin'])->name('categoryList');
Route::post('addCategory', [ReelController::class, 'addCategory'])->middleware(['checkLogin', 'superAdmin'])->name('addCategory');
Route::post('updateCategory', [ReelController::class, 'updateCategory'])->middleware(['checkLogin', 'superAdmin'])->name('updateCategory');
Route::post('deleteCategory', [ReelController::class, 'deleteCategory'])->middleware(['checkLogin', 'superAdmin'])->name('deleteCategory');
Route::post('musicList', [ReelController::class, 'musicList'])->middleware(['checkLogin'])->name('musicList');
Route::post('addMusic', [ReelController::class, 'addMusic'])->middleware(['checkLogin', 'superAdmin'])->name('addMusic');
Route::post('updateMusic', [ReelController::class, 'updateMusic'])->middleware(['checkLogin', 'superAdmin'])->name('updateMusic');
Route::post('deleteMusic', [ReelController::class, 'deleteMusic'])->middleware(['checkLogin', 'superAdmin'])->name('deleteMusic');

Route::post('userReportList', [UserController::class, 'userReportList'])->middleware(['checkLogin'])->name('userReportList');
Route::post('deleteUserReport', [UserController::class, 'deleteUserReport'])->middleware(['checkLogin', 'superAdmin'])->name('deleteUserReport');
Route::post('blockUserFromReport', [UserController::class, 'blockUserFromReport'])->middleware(['checkLogin', 'superAdmin'])->name('blockUserFromReport');
Route::get('users', [UserController::class, 'users'])->middleware(['checkLogin'])->name('users');
Route::post('userListWeb', [UserController::class, 'userListWeb'])->middleware(['checkLogin'])->name('userListWeb');
Route::post('verifiedUserList', [UserController::class, 'verifiedUserList'])->middleware(['checkLogin'])->name('verifiedUserList');
Route::post('verifiedUserBySubscriptionList', [UserController::class, 'verifiedUserBySubscriptionList'])->middleware(['checkLogin'])->name('verifiedUserBySubscriptionList');
Route::post('blockUserByAdmin/{id}', [UserController::class, 'blockUserByAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('blockUserByAdmin');
Route::post('unblockUserByAdmin/{id}', [UserController::class, 'unblockUserByAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('unblockUserByAdmin');
Route::get('usersDetail/{id}', [UserController::class, 'usersDetail'])->middleware(['checkLogin'])->name('usersDetail');
Route::post('verifyUser', [UserController::class, 'verifyUser'])->middleware(['checkLogin', 'superAdmin'])->name('verifyUser');
Route::post('userPostsList', [UserController::class, 'userPostsList'])->middleware(['checkLogin'])->name('userPostsList');
Route::post('deletePostFromUserPostTable', [UserController::class, 'deletePostFromUserPostTable'])->middleware(['checkLogin', 'superAdmin'])->name('deletePostFromUserPostTable');
Route::post('editProfileFormWeb', [UserController::class, 'editProfileFormWeb'])->middleware(['checkLogin', 'superAdmin'])->name('editProfileFormWeb');
Route::post('updateModeratorStatus', [UserController::class, 'updateModeratorStatus'])->middleware(['checkLogin', 'superAdmin'])->name('updateModeratorStatus');
Route::post('deleteAvatarFromUserDetail', [UserController::class, 'deleteAvatarFromUserDetail'])->middleware(['checkLogin', 'superAdmin'])->name('deleteAvatarFromUserDetail');
Route::post('deleteProfileFromUserDetail', [UserController::class, 'deleteProfileFromUserDetail'])->middleware(['checkLogin', 'superAdmin'])->name('deleteProfileFromUserDetail');
Route::post('moderatorsList', [UserController::class, 'moderatorsList'])->middleware(['checkLogin'])->name('moderatorsList');

Route::get('faqs', [FAQsController::class, 'faqs'])->middleware(['checkLogin'])->name('faqs');
Route::post('faqsList', [FAQsController::class, 'faqsList'])->middleware(['checkLogin'])->name('faqsList');
Route::post('addFAQsType', [FAQsController::class, 'addFAQsType'])->middleware(['checkLogin', 'superAdmin'])->name('addFAQsType');
Route::post('updateFAQsType', [FAQsController::class, 'updateFAQsType'])->middleware(['checkLogin', 'superAdmin'])->name('updateFAQsType');
Route::post('deleteFAQsType', [FAQsController::class, 'deleteFAQsType'])->middleware(['checkLogin', 'superAdmin'])->name('deleteFAQsType');
Route::post('faqsTypeList', [FAQsController::class, 'faqsTypeList'])->middleware(['checkLogin'])->name('faqsTypeList');
Route::post('addFAQs', [FAQsController::class, 'addFAQs'])->middleware(['checkLogin', 'superAdmin'])->name('addFAQs');
Route::post('updateFAQs', [FAQsController::class, 'updateFAQs'])->middleware(['checkLogin', 'superAdmin'])->name('updateFAQs');
Route::post('deleteFAQs', [FAQsController::class, 'deleteFAQs'])->middleware(['checkLogin', 'superAdmin'])->name('deleteFAQs');

Route::get('viewStories', [PostController::class, 'viewStories'])->middleware(['checkLogin'])->name('viewStories');
Route::post('userStoryList', [PostController::class, 'userStoryList'])->middleware(['checkLogin'])->name('userStoryList');
Route::post('allStoriesList', [PostController::class, 'allStoriesList'])->middleware(['checkLogin'])->name('allStoriesList');
Route::post('deleteStoryFromAdmin', [PostController::class, 'deleteStoryFromAdmin'])->middleware(['checkLogin', 'superAdmin'])->name('deleteStoryFromAdmin');

Route::get('restrictions', [RestrictionController::class, 'restrictions'])->middleware(['checkLogin'])->name('restrictions');
Route::post('usernameRestrictionsList', [RestrictionController::class, 'usernameRestrictionsList'])->middleware(['checkLogin'])->name('usernameRestrictionsList');
Route::post('addUsernameRestrict', [RestrictionController::class, 'addUsernameRestrict'])->middleware(['checkLogin', 'superAdmin'])->name('addUsernameRestrict');
Route::post('updateUsernameRestrict', [RestrictionController::class, 'updateUsernameRestrict'])->middleware(['checkLogin', 'superAdmin'])->name('updateUsernameRestrict');
Route::post('deleteUsernameRestrictions', [RestrictionController::class, 'deleteUsernameRestrictions'])->middleware(['checkLogin', 'superAdmin'])->name('deleteUsernameRestrictions');



Route::get('admob', [SettingsController::class, 'admob'])->middleware(['checkLogin'])->name('admob');
Route::post('admobAndroid', [SettingsController::class, 'admobAndroid'])->middleware(['checkLogin', 'superAdmin'])->name('admobAndroid');
Route::post('admobiOS', [SettingsController::class, 'admobiOS'])->middleware(['checkLogin', 'superAdmin'])->name('admobiOS');

Route::post('addFakeData', [SettingsController::class, 'addFakeData'])->middleware(['checkLogin', 'superAdmin'])->name('addFakeData');
