<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FAQsController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileVerificationController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::get('health', [OpsController::class, 'health'])->middleware('throttle:60,1');
Route::get('readiness', [OpsController::class, 'readiness'])->middleware('throttle:30,1');

Route::post('testupload', [PostController::class, 'testfileupload'])->middleware(['checkHeader', 'throttle:uploads', 'requestSizeLimit:10']);

// Route::post('fetchUserList', [UserController::class, 'fetchUserList'])->middleware('checkHeader');
Route::post('addUser', [UserController::class, 'addUser'])->middleware(['checkHeader', 'throttle:auth']);
Route::post('editProfile', [UserController::class, 'editProfile'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:16']);
Route::post('followUser', [UserController::class, 'followUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchFollowingList', [UserController::class, 'fetchFollowingList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchFollowersList', [UserController::class, 'fetchFollowersList'])->middleware('checkHeader');
Route::post('unfollowUser', [UserController::class, 'unfollowUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('searchFollower', [UserController::class, 'searchFollower'])->middleware(['checkHeader', 'throttle:search']);
Route::post('checkUsername', [UserController::class, 'checkUsername'])->middleware('checkHeader');
Route::post('fetchRandomProfile', [UserController::class, 'fetchRandomProfile'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('reportUser', [UserController::class, 'reportUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchBlockedUserList', [UserController::class, 'fetchBlockedUserList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchPostByUser', [UserController::class, 'fetchPostByUser'])->middleware('checkHeader');
Route::post('fetchProfile', [UserController::class, 'fetchProfile'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('deleteUser', [UserController::class, 'deleteUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('searchProfile', [UserController::class, 'searchProfile'])->middleware(['checkHeader', 'authorizeUser', 'throttle:search']);

// Moderator
Route::prefix('Moderator')->middleware(['checkHeader', 'authorizeUser', 'ensureModerator', 'throttle:moderation'])->group(function () {
    Route::post('deletePostByModerator', [UserController::class, 'deletePostByModerator'])->middleware('ensureModeratorAction:delete_post');
    Route::post('deleteCommentByModerator', [UserController::class, 'deleteCommentByModerator'])->middleware('ensureModeratorAction:delete_comment');
    Route::post('deleteRoomByModerator', [UserController::class, 'deleteRoomByModerator'])->middleware('ensureModeratorAction:delete_room');
    Route::post('deleteStoryByModerator', [UserController::class, 'deleteStoryByModerator'])->middleware('ensureModeratorAction:delete_story');
    Route::post('userBlockByModerator', [UserController::class, 'userBlockByModerator'])->middleware('ensureModeratorAction:block_user');
    Route::post('deleteReelCommentByModerator', [UserController::class, 'deleteReelCommentByModerator'])->middleware('ensureModeratorAction:delete_reel_comment');
    Route::post('deleteReelByModerator', [UserController::class, 'deleteReelByModerator'])->middleware('ensureModeratorAction:delete_reel');
});

Route::prefix('ModerationAppeal')->middleware(['checkHeader', 'authorizeUser', 'throttle:writes'])->group(function () {
    Route::post('submit', [UserController::class, 'submitModerationAppeal']);
    Route::post('fetchMyAppeals', [UserController::class, 'fetchMyModerationAppeals']);
});

Route::post('uploadReel', [ReelController::class, 'uploadReel'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:120']);
Route::post('likeDislikeReel', [ReelController::class, 'likeDislikeReel'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('increaseReelViewCount', [ReelController::class, 'increaseReelViewCount'])->middleware(['checkHeader', 'throttle:engagement']);
Route::post('addReelComment', [ReelController::class, 'addReelComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchReelComments', [ReelController::class, 'fetchReelComments'])->middleware('checkHeader');
Route::post('fetchReelCommentReplies', [ReelController::class, 'fetchReelCommentReplies'])->middleware('checkHeader');
Route::post('editReelComment', [ReelController::class, 'editReelComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('deleteReelComment', [ReelController::class, 'deleteReelComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('likeDislikeReelComment', [ReelController::class, 'likeDislikeReelComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('deleteReel', [ReelController::class, 'deleteReel'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchReelsByUserId', [ReelController::class, 'fetchReelsByUserId'])->middleware('checkHeader');
Route::post('fetchReelsOnExplore', [ReelController::class, 'fetchReelsOnExplore'])->middleware('checkHeader');
Route::post('searchReelsByInterestId', [ReelController::class, 'searchReelsByInterestId'])->middleware(['checkHeader', 'throttle:search']);
Route::post('fetchReelsByHashtag', [ReelController::class, 'fetchReelsByHashtag'])->middleware('checkHeader');
Route::post('reportReel', [ReelController::class, 'reportReel'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchSavedReels', [ReelController::class, 'fetchSavedReels'])->middleware('checkHeader');
Route::post('fetchReelById', [ReelController::class, 'fetchReelById'])->middleware('checkHeader');

Route::post('fetchMusicWithSearch', [ReelController::class, 'fetchMusicWithSearch'])->middleware('checkHeader');
Route::post('fetchMusicCategories', [ReelController::class, 'fetchMusicCategories'])->middleware('checkHeader');
Route::post('fetchSavedMusic', [ReelController::class, 'fetchSavedMusic'])->middleware('checkHeader');
Route::post('fetchMusicByCategory', [ReelController::class, 'fetchMusicByCategory'])->middleware('checkHeader');
Route::post('fetchReelsByMusic', [ReelController::class, 'fetchReelsByMusic'])->middleware('checkHeader');

Route::post('logOut', [UserController::class, 'logOut'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('UserBlockedByUser', [UserController::class, 'UserBlockedByUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('UserUnblockedByUser', [UserController::class, 'UserUnblockedByUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchUserNotification', [UserController::class, 'fetchUserNotification'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('markNotificationsAsRead', [UserController::class, 'markNotificationsAsRead'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchUnreadNotificationCount', [UserController::class, 'fetchUnreadNotificationCount'])->middleware(['checkHeader', 'authorizeUser']);

Route::post('profileVerification', [ProfileVerificationController::class, 'profileVerification'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:20']);

Route::post('addPost', [PostController::class, 'addPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:80']);
Route::post('editPost', [PostController::class, 'editPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('repostPost', [PostController::class, 'repostPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchReposts', [PostController::class, 'fetchReposts'])->middleware('checkHeader');
Route::post('fetchPosts', [PostController::class, 'fetchPosts'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('addComment', [PostController::class, 'addComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchComments', [PostController::class, 'fetchComments'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchReplies', [PostController::class, 'fetchReplies'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('editComment', [PostController::class, 'editComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('deleteComment', [PostController::class, 'deleteComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('likePost', [PostController::class, 'likePost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('dislikePost', [PostController::class, 'dislikePost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('reportPost', [PostController::class, 'reportPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('deleteMyPost', [PostController::class, 'deleteMyPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchPostByPostId', [PostController::class, 'fetchPostByPostId'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchPostsByHashtag', [PostController::class, 'fetchPostsByHashtag'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('createStory', [PostController::class, 'createStory'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:60']);
Route::post('viewStory', [PostController::class, 'viewStory'])->middleware(['checkHeader', 'authorizeUser', 'throttle:engagement']);
Route::post('fetchStory', [PostController::class, 'fetchStory'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('deleteStory', [PostController::class, 'deleteStory'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchStoryByID', [PostController::class, 'fetchStoryByID'])->middleware('checkHeader');
Route::post('uploadFile', [PostController::class, 'uploadFile'])->middleware(['checkHeader', 'throttle:uploads', 'requestSizeLimit:32']);
Route::post('fetchUsersWhoLikedPost', [PostController::class, 'fetchUsersWhoLikedPost'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('searchHashtag', [PostController::class, 'searchHashtag'])->middleware(['checkHeader', 'authorizeUser', 'throttle:search']);
Route::post('searchPost', [PostController::class, 'searchPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:search']);
Route::post('likeDislikeComment', [PostController::class, 'likeDislikeComment'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('searchPostByInterestId', [PostController::class, 'searchPostByInterestId'])->middleware(['checkHeader', 'authorizeUser', 'throttle:search']);
Route::post('fetchSavedPosts', [PostController::class, 'fetchSavedPosts'])->middleware(['checkHeader', 'authorizeUser']);

Route::post('fetchInterests', [InterestController::class, 'fetchInterests'])->middleware('checkHeader');

Route::post('createRoom', [RoomController::class, 'createRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:12']);
Route::post('inviteUserToRoom', [RoomController::class, 'inviteUserToRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('joinOrRequestRoom', [RoomController::class, 'joinOrRequestRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('getInvitationList', [RoomController::class, 'getInvitationList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('acceptInvitation', [RoomController::class, 'acceptInvitation'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('acceptRoomRequest', [RoomController::class, 'acceptRoomRequest'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('rejectInvitation', [RoomController::class, 'rejectInvitation'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchRoomRequestList', [RoomController::class, 'fetchRoomRequestList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('rejectRoomRequest', [RoomController::class, 'rejectRoomRequest'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('removeUserFromRoom', [RoomController::class, 'removeUserFromRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('makeRoomAdmin', [RoomController::class, 'makeRoomAdmin'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchRoomUsersList', [RoomController::class, 'fetchRoomUsersList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('reportRoom', [RoomController::class, 'reportRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('leaveThisRoom', [RoomController::class, 'leaveThisRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchRoomDetail', [RoomController::class, 'fetchRoomDetail'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('deleteRoom', [RoomController::class, 'deleteRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchMyOwnRooms', [RoomController::class, 'fetchMyOwnRooms'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('editRoom', [RoomController::class, 'editRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:12']);
Route::post('fetchSuggestedRooms', [RoomController::class, 'fetchSuggestedRooms'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchRoomsByInterest', [RoomController::class, 'fetchRoomsByInterest'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('searchUserForInvitation', [RoomController::class, 'searchUserForInvitation'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchRandomRooms', [RoomController::class, 'fetchRandomRooms'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('fetchRoomAdmins', [RoomController::class, 'fetchRoomAdmins'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('removeAdminFromRoom', [RoomController::class, 'removeAdminFromRoom'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchRoomsList', [RoomController::class, 'fetchRoomsList'])->middleware(['checkHeader', 'authorizeUser']);
Route::post('muteUnmuteRoomNotification', [RoomController::class, 'muteUnmuteRoomNotification'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('fetchRoomsIAmIn', [RoomController::class, 'fetchRoomsIAmIn'])->middleware(['checkHeader', 'authorizeUser']);

Route::post('fetchSetting', [SettingsController::class, 'fetchSetting'])->middleware('checkHeader');
Route::post('pushNotificationToSingleUser', [SettingsController::class, 'pushNotificationToSingleUser'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
Route::post('generateAgoraToken', [SettingsController::class, 'generateAgoraToken'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);

Route::post('fetchPlatformNotification', [AdminController::class, 'fetchPlatformNotification'])->middleware('checkHeader');

Route::post('fetchFAQs', [FAQsController::class, 'fetchFAQs'])->middleware('checkHeader');

Route::post('test', [PostController::class, 'test'])->middleware('checkHeader');

// ─── Job Board: Company auth & profile ───
Route::prefix('Company')->group(function () {
    Route::post('register', [CompanyController::class, 'register'])->middleware(['checkHeader', 'throttle:auth']);
    Route::post('login', [CompanyController::class, 'login'])->middleware(['checkHeader', 'throttle:auth']);
    Route::post('verifyEmail', [CompanyController::class, 'verifyEmail'])->middleware(['checkHeader', 'throttle:auth']);
    Route::post('resendVerification', [CompanyController::class, 'resendVerification'])->middleware(['checkHeader', 'throttle:auth']);
    Route::post('editProfile', [CompanyController::class, 'editProfile'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:8']);
    Route::post('fetchProfile', [CompanyController::class, 'fetchProfile'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('fetchDashboard', [CompanyController::class, 'fetchDashboard'])->middleware(['checkHeader', 'authorizeUser']);
    // Public-facing (ITGA user can call these)
    Route::post('publicProfile', [CompanyController::class, 'publicProfile'])->middleware('checkHeader');
    Route::post('followCompany', [CompanyController::class, 'followCompany'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('unfollowCompany', [CompanyController::class, 'unfollowCompany'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('fetchFollowedCompanies', [CompanyController::class, 'fetchFollowedCompanies'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('createPost', [CompanyController::class, 'createPost'])->middleware(['checkHeader', 'authorizeUser', 'throttle:uploads', 'requestSizeLimit:80']);
});

// ─── Job Board: Job offers ───
Route::prefix('Job')->group(function () {
    // User-facing
    Route::post('fetchJobs', [JobController::class, 'fetchJobs'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('fetchJobDetail', [JobController::class, 'fetchJobDetail'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('toggleSaveJob', [JobController::class, 'toggleSaveJob'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('fetchSavedJobs', [JobController::class, 'fetchSavedJobs'])->middleware(['checkHeader', 'authorizeUser']);

    // Company-facing
    Route::post('createJob', [JobController::class, 'createJob'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('editJob', [JobController::class, 'editJob'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('deleteJob', [JobController::class, 'deleteJob'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
    Route::post('fetchCompanyJobs', [JobController::class, 'fetchCompanyJobs'])->middleware(['checkHeader', 'authorizeUser']);
});

// ─── Job Board: Applications ───
Route::prefix('Application')->group(function () {
    Route::post('applyToJob', [ApplicationController::class, 'applyToJob'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes', 'requestSizeLimit:8']);
    Route::post('fetchMyApplications', [ApplicationController::class, 'fetchMyApplications'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('fetchJobApplications', [ApplicationController::class, 'fetchJobApplications'])->middleware(['checkHeader', 'authorizeUser']);
    Route::post('updateApplicationStatus', [ApplicationController::class, 'updateApplicationStatus'])->middleware(['checkHeader', 'authorizeUser', 'throttle:writes']);
});

// ─── Job Board: Admin moderation ───
Route::prefix('AdminJob')->middleware(['checkHeader', 'checkAdminHeader', 'throttle:adminWrites'])->group(function () {
    Route::post('fetchAllJobs', [ApplicationController::class, 'fetchAllJobsAdmin']);
    Route::post('moderateJob', [ApplicationController::class, 'moderateJob']);
    Route::post('fetchCompanies', [ApplicationController::class, 'fetchCompaniesAdmin']);
    Route::post('toggleSuspendCompany', [ApplicationController::class, 'toggleSuspendCompany']);
    Route::post('toggleVerifyCompany', [ApplicationController::class, 'toggleVerifyCompany']);
    Route::post('fetchJobKPIs', [ApplicationController::class, 'fetchJobKPIs']);
});

Route::prefix('AdminModeration')->middleware(['checkHeader', 'checkAdminHeader', 'throttle:adminWrites'])->group(function () {
    Route::post('fetchAuditLogs', [UserController::class, 'fetchModerationAuditLogsAdmin']);
    Route::post('exportAuditLogs', [UserController::class, 'exportModerationAuditLogsAdmin']);
    Route::post('fetchAuditAppeals', [UserController::class, 'fetchModerationAppealsAdmin']);
    Route::post('reviewAuditAppeal', [UserController::class, 'reviewModerationAppealAdmin']);
});
