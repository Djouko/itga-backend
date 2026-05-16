<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Company;
use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\Interest;
use App\Models\Report;
use App\Models\Room;
use App\Models\RoomUser;
use App\Models\SavedNotification;
use App\Models\Setting;
use App\Models\User;
use App\Services\RoomSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoomController extends Controller
{
    private function resolveCompanyActor(Request $request, User $user)
    {
        if (!$request->filled('company_id')) {
            return null;
        }

        $company = Company::find((int) $request->company_id);
        if (!$company || (int) $company->is_suspended === 1) {
            return response()->json([
                'status' => false,
                'message' => 'Company is not allowed to interact.',
            ]);
        }

        if ((int) $company->owner_user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Only the linked company owner can use company mode.',
            ]);
        }

        return $company;
    }

    private function actorDisplayName(User $user, $companyActor = null): string
    {
        return $companyActor ? $companyActor->name : $user->full_name;
    }

    private function applyRoomUserActorScope($query, $companyActor = null): void
    {
        if ($companyActor) {
            $query->where('company_id', $companyActor->id);
            return;
        }

        $query->where(function ($actorQuery) {
            $actorQuery->whereNull('company_id')
                ->orWhere('company_id', 0);
        });
    }

    private function resolveAuthenticatedRoomActor(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $actorUser = User::where('is_block', 0)->where('id', (int) $request->my_user_id)->first();
        if (!$actorUser) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $actorUser);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        return [
            'user' => $actorUser,
            'company' => $companyActor,
        ];
    }

    private function findActorRoomMembership(int $roomId, int $actorUserId, $companyActor = null)
    {
        $membershipQuery = RoomUser::where('room_id', $roomId)->where('user_id', $actorUserId);
        $this->applyRoomUserActorScope($membershipQuery, $companyActor);

        return $membershipQuery->first();
    }

    private function ensureActorRoomPermission(
        int $roomId,
        int $actorUserId,
        $companyActor = null,
        array $allowedTypes = [3, 5],
        string $message = 'You are not allowed to manage this room.',
        string $errorCode = 'room_management_forbidden'
    ) {
        $room = Room::where('id', $roomId)->first();
        if ($room && in_array(5, $allowedTypes, true) && (int) $room->admin_id === $actorUserId) {
            if ($companyActor && (int) ($room->company_id ?? 0) === (int) $companyActor->id) {
                return null;
            }

            if (!$companyActor && ((int) ($room->company_id ?? 0) === 0)) {
                return null;
            }
        }

        $membership = $this->findActorRoomMembership($roomId, $actorUserId, $companyActor);
        if (!$membership || !in_array((int) $membership->type, $allowedTypes, true)) {
            return response()->json([
                'status' => false,
                'message' => $message,
                'error_code' => $errorCode,
            ], 403);
        }

        return null;
    }

    public function createRoom(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'admin_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'desc' => 'required',
            'interest_ids' => 'required',
            'is_private' => 'required',
            'is_join_request_enable' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $userData = User::where('id', $request->admin_id)->first();

        if ($userData) {
            $companyActor = $this->resolveCompanyActor($request, $userData);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }

            $room = new Room();
            $room->admin_id = (int) $request->admin_id;
            $room->company_id = $companyActor ? (int) $companyActor->id : null;
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $path = GlobalFunction::saveFileAndGivePath($file);
                $room->photo = $path;
            }

            $room->title = InputSanitizer::sanitizeText($request->title, 200);
            $room->desc = InputSanitizer::sanitizeText($request->desc, 2000);
            $room->interest_ids = InputSanitizer::sanitizeIdList($request->interest_ids);
            $room->is_private = (int) $request->is_private;
            $room->is_join_request_enable = (int) $request->is_join_request_enable;
            $room->total_member += 1;
            $room->save();

            $roomUser = new RoomUser();
            $roomUser->room_id = $room->id;
            $roomUser->user_id = $room->admin_id;
            $roomUser->company_id = $companyActor ? (int) $companyActor->id : null;
            $roomUser->type = 5;
            $roomUser->save();
            $room->load('user', 'company');

            return response()->json([
                'status' => true,
                'message' => 'Room Created Successfully',
                'data' => $room,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function editRoom(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $room = Room::where('id', (int) $request->room_id)->first();
        if (!$room) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $room->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [5],
            'Only room owner can edit this room.',
            'room_edit_forbidden'
        )) {
            return $permissionError;
        }

        if ($request->hasFile('photo')) {
            GlobalFunction::deleteFile($room->photo);
            $file = $request->file('photo');
            $path = GlobalFunction::saveFileAndGivePath($file);
            $room->photo = $path;
        }
        if ($request->has('title')) {
            $room->title = InputSanitizer::sanitizeText($request->title, 200);
        }
        if ($request->has('desc')) {
            $room->desc = InputSanitizer::sanitizeText($request->desc, 2000);
        }
        if ($request->has('interest_ids')) {
            $room->interest_ids = InputSanitizer::sanitizeIdList($request->interest_ids);
        }
        if ($request->has('is_private')) {
            $room->is_private = (int) $request->is_private;
        }
        if ($request->has('is_join_request_enable')) {
            $room->is_join_request_enable = (int) $request->is_join_request_enable;
        }
        $room->save();

        return response()->json([
            'status' => true,
            'message' => 'Room edit successfully',
            'data' => $room,
        ]);
    }

    public function inviteUserToRoom(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $roomRequest = Room::with(['user', 'company'])->where('id', (int) $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $roomRequest->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [3, 5],
            'You are not allowed to invite users in this room.',
            'room_invite_forbidden'
        )) {
            return $permissionError;
        }

        $user = User::where('is_block', 0)->where('id', (int) $request->user_id)->first();
        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $actorContext['company'];

        $roomAdmin = Room::where('id', (int) $request->room_id)->where('admin_id', (int) $request->user_id)->first();
        if($roomAdmin != null) {
            return response()->json([
                'status' => false,
                'message' => 'Target user is already room owner',
            ]);
        }

        if ($user && $roomRequest) {
            $roomRequestData = RoomUser::where('room_id', (int) $request->room_id)
                                        ->where('user_id', (int) $request->user_id)
                                        ->first();

            if ($roomRequestData != null) {
                return response()->json([
                    'status' => false,
                    'message' => 'User record already exists',
                ]);
            }

            $roomUser = RoomUser::where('room_id', (int) $request->room_id)
                                ->where(function($query){
                                    $query->where('type', 2)
                                        ->orWhere('type', 3)
                                        ->orWhere('type', 5);
                                })->count();

            $setting = Setting::first();
            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Room settings not found',
                ]);
            }

            if ($roomUser < $setting->setRoomUsersLimit) {

                $invitedByAdmin = 4;

                $roomUser = new RoomUser;
                $roomUser->room_id = (int) $request->room_id;
                $roomUser->user_id = (int) $request->user_id;
                $roomUser->invited_by = (int) $request->my_user_id;
                $roomUser->invited_by_company_id = $companyActor ? (int) $companyActor->id : null;
                $roomUser->type = $invitedByAdmin;
                $roomUser->save();

                $fromUser = (int) $request->my_user_id;

                if ($fromUser != $roomUser->user_id) {
                    if($user->is_push_notifications == 1) {
                        $notificationDesc = $this->actorDisplayName($actorContext['user'], $companyActor) . ' has invited to room : ' . $roomRequest->title;
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $user->device_token, $user->device_type);
                    }
                }

                $roomUser->room = $roomRequest;

                $type = Constants::notificationTypeInviteRoom;

                $savedNotification = new SavedNotification();
                $savedNotification->my_user_id = (int) $request->user_id;
                $savedNotification->user_id = (int) $request->my_user_id;
                $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                $savedNotification->room_id = (int) $request->room_id;
                $savedNotification->type = $type;
                $savedNotification->save();

                return response()->json([
                    'status' => true,
                    'message' => 'Request Send',
                    'data' => $roomUser,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Room users limit reached',
                ]);
            }
        }
    }

    public function joinOrRequestRoom(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();
        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
        $roomRequest = Room::with(['user', 'company'])->where('id', $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        if ($roomRequest) {
            $roomRequestData = RoomUser::where('room_id', $request->room_id)->where('user_id', $request->user_id);
            $this->applyRoomUserActorScope($roomRequestData, $companyActor);
            $roomRequestData = $roomRequestData->first();

            if ($roomRequestData != null) {
                return response()->json([
                    'status' => false,
                    'message' => 'Already in room users list',
                ]);
            }

            if ($roomRequest->is_join_request_enable == 1) {
                $roomUser = RoomUser::where('room_id', $request->room_id)
                                    ->where(function($query){
                                        $query->where('type', 2)
                                        ->orWhere('type', 3);
                                    })->count();

                $setting = Setting::get()->first();

                if ($roomUser < $setting->setRoomUsersLimit) {
                    $requestToJoin = 1;
                    $roomUser = new RoomUser();
                    $roomUser->room_id = (int) $request->room_id;
                    $roomUser->user_id = (int) $request->user_id;
                    $roomUser->company_id = $companyActor ? (int) $companyActor->id : null;
                    $roomUser->type = $requestToJoin;
                    $roomUser->save();


                    if ($user->id != $roomRequest->admin_id) {
                        if($roomRequest->user->is_push_notifications == 1 && $roomRequest->user->device_token != null) {
                            $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' has requested to join your room : ' . $roomRequest->title;
                            GlobalFunction::sendPushNotificationToUser($notificationDesc, $roomRequest->user->device_token, $roomRequest->user->device_type);
                        }
                    }

                    $roomUser->room = $roomRequest;

                    $type = Constants::notificationTypejoinRoom;

                    $savedNotification = new SavedNotification();
                    $savedNotification->my_user_id = (int) $roomUser->room->admin_id;
                    $savedNotification->user_id = (int) $request->user_id;
                    $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                    $savedNotification->room_id = (int) $request->room_id;
                    $savedNotification->type = $type;
                    $savedNotification->save();


                    return response()->json([
                        'status' => true,
                        'message' => 'Request Send',
                        'data' => $roomUser,
                    ]);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Room users limit reached',
                    ]);
                }
            } else {
                $roomUser = RoomUser::where('room_id', $request->room_id)
                                    ->where(function($query){
                                    $query->where('type', 2)
                                        ->orWhere('type', 3)
                                        ->orWhere('type', 5);
                                    })->count();
                $setting = Setting::get()->first();

                if ($roomUser < $setting->setRoomUsersLimit) {
                    $directJoin = 2;
                    $roomUser = new RoomUser;
                    $roomUser->room_id = (int) $request->room_id;
                    $roomUser->user_id = (int) $request->user_id;
                    $roomUser->company_id = $companyActor ? (int) $companyActor->id : null;
                    $roomUser->type = $directJoin;
                    $roomUser->save();

                    $roomTotalUserCount = Room::where('id', $request->room_id)->first();
                    $roomTotalUserCount->total_member += 1;
                    $roomTotalUserCount->save();

                    if ($user->id != $roomRequest->admin_id) {
                        if ($roomRequest->user->is_push_notifications == 1 && $roomRequest->user->device_token != null) {
                            $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' has joined your room : ' . $roomRequest->title;
                            GlobalFunction::sendPushNotificationToUser($notificationDesc, $roomRequest->user->device_token, $roomRequest->user->device_type);
                        }
                    }

                    $roomUser->room = $roomRequest;

                    $type = Constants::notificationTypeDirectjoinRoom;

                    $savedNotification = new SavedNotification();
                    $savedNotification->my_user_id = (int) $roomUser->room->admin_id;
                    $savedNotification->user_id = (int) $request->user_id;
                    $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                    $savedNotification->room_id = (int) $request->room_id;
                    $savedNotification->type = $type;
                    $savedNotification->save();

                    return response()->json([
                        'status' => true,
                        'message' => 'Great, You are in the room',
                        'data' => $roomUser,
                    ]);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Room users limit reached',
                    ]);
                }
            }
        }
    }

    public function getInvitationList(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'start' => 'required',
            'limit' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }


        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $getInvitationList = RoomUser::where('user_id', $request->user_id)
            ->where('type', 4)
            ->with(['room.company', 'invited_user', 'invited_company', 'company']);
        $this->applyRoomUserActorScope($getInvitationList, $companyActor);
        $getInvitationList = $getInvitationList
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Get All Room Requests (Invitation)',
            'data' => $getInvitationList,
        ]);
    }

    public function acceptInvitation(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();

        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomRequest = Room::with(['user', 'company'])->where('id', $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        $acceptRequest = RoomUser::where('room_id', $request->room_id)
                                ->where('user_id', $request->user_id);
        $this->applyRoomUserActorScope($acceptRequest, $companyActor);
        $acceptRequest = $acceptRequest->first();
        if (!$acceptRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Invitation not found',
            ]);
        }

        if ($acceptRequest->type == 2) {
            return response()->json([
                'status' => false,
                'message' => 'Invitation Already Accepted',
            ]);
        }

        if ($acceptRequest) {
            $invitedByUser = User::where('id', $acceptRequest->invited_by)->first();

            if ($acceptRequest->type == 1 || $acceptRequest->type == 4) {
                $acceptRequest->type = 2;

                $userCount = Room::where('id', $request->room_id)->first();
                $userCount->total_member += 1;
                $userCount->save();

                $acceptRequest->save();

                if ($invitedByUser && $user->id != $invitedByUser->id) {
                    if($invitedByUser->is_push_notifications == 1) {
                        $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' has accepted your invitation of room : '. $roomRequest->title ;
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $invitedByUser->device_token, $invitedByUser->device_type);
                    }
                }

                $acceptRequest->room = $roomRequest;

                $type = Constants::notificationTypeAcceptInvitationRoom;

                $savedNotification = new SavedNotification();
                $savedNotification->my_user_id = (int) $acceptRequest->room->admin_id;
                $savedNotification->user_id = (int) $request->user_id;
                $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                $savedNotification->room_id = (int) $request->room_id;
                $savedNotification->type = $type;
                $savedNotification->save();


                return response()->json([
                    'status' => true,
                    'message' => 'Accept Invitation',
                    'data' => $acceptRequest,
                ]);


            }


        }
    }

    public function acceptRoomRequest(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $roomRequest = Room::with(['user', 'company'])->where('id', (int) $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $roomRequest->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [3, 5],
            'You are not allowed to review room join requests.',
            'room_request_review_forbidden'
        )) {
            return $permissionError;
        }

        $targetUser = User::where('is_block', 0)->where('id', (int) $request->user_id)->first();
        if ($targetUser == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $actorContext['company'];

        $acceptRoomRequest = RoomUser::where('room_id', (int) $request->room_id)
                                    ->where('user_id', (int) $request->user_id)
                                    ->first();
        if ($acceptRoomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Record Not found',
            ]);
        }

        if ($acceptRoomRequest->type == 2) {
            return response()->json([
                'status' => false,
                'message' => 'Invitation Already Accepted',
            ]);
        }

        if (!in_array((int) $acceptRoomRequest->type, [1, 4], true)) {
            return response()->json([
                'status' => false,
                'message' => 'This join request is not pending.',
            ]);
        }

        if ($acceptRoomRequest) {
            $roomUser = RoomUser::where('room_id', (int) $request->room_id)
                                ->where(function($query){
                                $query->where('type', 2)
                                    ->orWhere('type', 3)
                                    ->orWhere('type', 5);
                                })->count();
            $setting = Setting::first();
            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Room settings not found',
                ]);
            }
            if ($roomUser < $setting->setRoomUsersLimit) {

                $roomUser = RoomUser::where('id', $acceptRoomRequest->id)->first();
                $roomUser->type = 2;
                $roomRequest->total_member += 1;
                $roomRequest->save();
                $roomUser->save();


                if ((int) $request->my_user_id !== (int) $targetUser->id) {
                    if($targetUser->is_push_notifications == 1) {
                        $notificationDesc = $this->actorDisplayName($actorContext['user'], $companyActor) . ' has accepted your join request of room : '. $roomRequest->title ;
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $targetUser->device_token, $targetUser->device_type);
                    }
                }

                $roomUser->room = $roomRequest;

                $userNotification = SavedNotification::where('room_id', $request->room_id)
                                                    ->where('user_id', $request->user_id)
                                                    ->where('type', Constants::notificationTypejoinRoom)
                                                    ->first();
                if ($userNotification) {
                    $userNotification->delete();
                }


                $type = Constants::notificationTypeAcceptRoomRequest;

                $savedNotification = new SavedNotification();
                $savedNotification->my_user_id = (int) $request->user_id;
                $savedNotification->user_id = (int) $request->my_user_id;
                $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                $savedNotification->room_id = (int) $request->room_id;
                $savedNotification->type = $type;
                $savedNotification->save();



                return response()->json([
                    'status' => true,
                    'message' => 'Accept Room request',
                    'data' => $roomUser,
                ]);
            } else {
                    return response()->json([
                    'status' => false,
                    'message' => 'Room users limit reached',
                ]);
            }
        }
    }

    public function rejectInvitation(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();

        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomRequest = Room::where('id', $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        $rejectInvitation = RoomUser::where('room_id', $request->room_id)
            ->where('user_id', $request->user_id);
        $this->applyRoomUserActorScope($rejectInvitation, $companyActor);
        $rejectInvitation = $rejectInvitation->first();

        if ($rejectInvitation) {
            $roomUser = RoomUser::where('id', $rejectInvitation->id)
                ->get()
                ->first();

            if ($roomUser != null) {
                $roomUser->delete();
                return response()->json([
                    'status' => true,
                    'message' => 'Reject Invitation',
                    'data' => $roomUser,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'This User is not In your Room list',
                ]);
            }
        }
        return response()->json([
            'status' => false,
            'message' => 'Record not found',
        ]);
    }

    public function rejectRoomRequest(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $roomRequest = Room::where('id', (int) $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $roomRequest->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [3, 5],
            'You are not allowed to reject room join requests.',
            'room_request_review_forbidden'
        )) {
            return $permissionError;
        }

        $rejectInvitation = RoomUser::where('room_id', (int) $request->room_id)
                                    ->where('user_id', (int) $request->user_id)
                                    ->whereIn('type', [1, 4])
                                    ->first();

        if ($rejectInvitation) {

            $roomUser = RoomUser::where('id', $rejectInvitation->id)->first();
            $roomUser->delete();

            $userNotification = SavedNotification::where('room_id', $request->room_id)
                                                ->where('user_id', $request->user_id)
                                                ->where('type', Constants::notificationTypejoinRoom)
                                                ->first();
            if ($userNotification) {
                $userNotification->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Reject Room Request',
                'data' => $roomUser,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Record does not exist',
        ]);
    }

    public function fetchRoomRequestList(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }


        $room = Room::where('id', (int) $request->room_id)->first();
        if (!$room) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $room->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [3, 5],
            'You are not allowed to view room join requests.',
            'room_request_view_forbidden'
        )) {
            return $permissionError;
        }

        $roomUser = RoomUser::where('room_id', (int) $request->room_id)
                            ->where(function($query){
                            $query->where('type', 1);
                            // ->orWhere('type', 4);
                        })
                            ->with(['user', 'company'])
                            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Room Request List',
            'data' => $roomUser,
        ]);
    }

    public function fetchRoomUsersList(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $room = Room::where('id', (int) $request->room_id)->first();

        if($room) {
            if ($permissionError = $this->ensureActorRoomPermission(
                (int) $room->id,
                (int) $request->my_user_id,
                $actorContext['company'],
                [2, 3, 5],
                'You are not allowed to view room members.',
                'room_members_forbidden'
            )) {
                return $permissionError;
            }

            $roomUsers = RoomUser::where('room_id', (int) $request->room_id)
                        ->where(function($query){
                            $query->where('type', 2)
                            ->orWhere('type', 3)
                            ->orWhere('type', 5);
                        })
                        ->with(['user', 'company'])
                        ->offset((int) $request->start)
                        ->limit((int) $request->limit)
                        ->get();

            return response()->json([
                'status' => true,
                'message' => 'Fetch Room User List',
                'data' => $roomUsers,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Room not found',
        ]);
    }

    public function removeUserFromRoom(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $roomRequest = Room::where('id', (int) $request->room_id)->first();
        if ($roomRequest == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $roomRequest->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [3, 5],
            'You are not allowed to remove users from this room.',
            'room_remove_user_forbidden'
        )) {
            return $permissionError;
        }

        $removeUser = RoomUser::where('room_id', (int) $request->room_id)
            ->where('user_id', (int) $request->user_id)
            ->first();

        if ($removeUser) {
            if ((int) $removeUser->type === 5) {
                return response()->json([
                    'status' => false,
                    'message' => 'Room owner cannot be removed from room users.',
                ]);
            }

            $roomUser = RoomUser::where('id', $removeUser->id)->first();
            if ((int) $roomUser->type === 2 || (int) $roomUser->type === 3 || (int) $roomUser->type === 5) {
                $roomRequest->total_member = max(0, (int) $roomRequest->total_member - 1);
                $roomRequest->save();
            }

            $roomUser->delete();

            return response()->json([
                'status' => true,
                'message' => 'Remove user from Room',
                'data' => $roomUser,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User Not Found',
        ]);

    }

    public function reports()
    {
        return view('reports');
    }

    public function reportList(Request $request)
    {
        $reportType = 0;
        $totalData = Report::where('type', $reportType)->count();
        $rows = Report::where('type', $reportType)
            ->with(['room', 'user'])
            ->orderBy('id', 'DESC')
            ->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'room_id',
            2 => 'user_id',
            3 => 'reason',
            4 => 'desc',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = Report::where('type', $reportType)
                ->with(['room', 'user'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $buildSearchQuery = function () use ($reportType, $search) {
                return Report::where('type', $reportType)
                    ->where(function ($query) use ($search) {
                        $query->whereHas('room', function ($roomQuery) use ($search) {
                            $roomQuery->where('title', 'like', '%' . $search . '%');
                        })
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('identity', 'like', '%' . $search . '%')
                                    ->orWhere('full_name', 'like', '%' . $search . '%')
                                    ->orWhere('username', 'like', '%' . $search . '%');
                            })
                            ->orWhere('reason', 'like', '%' . $search . '%')
                            ->orWhere('desc', 'like', '%' . $search . '%');
                    });
            };

            $result = $buildSearchQuery()
                ->with(['room', 'user'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $buildSearchQuery()->count();
        }

        $data = [];
        foreach ($result as $item) {
            $userData = $item->user;
            $roomData = $item->room;

            $imageUrl = ($userData && $userData->profile) ? $userData->profile : 'asset/image/default.png';
            $image = '<img src="' . $imageUrl . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';

            $roomTitle = $roomData ? ($roomData->title ?? 'Room Not Found') : 'Room Not Found';
            $userIdentity = $userData ? ($userData->identity ?? 'Unknown user') : 'Unknown user';
            $description = $item->desc ?? 'No description';

            $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . '  data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';
            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteReportWithRoom d-flex align-items-center " rel=' . $item->id . '  data-tooltip="Delete Room" >' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> <span class="ms-2"> Delete Room </span> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $rejectReport . $delete . ' </span>';

            $data[] = [$image, $roomTitle, $userIdentity, $item->reason, $description, $action];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function reportRoom(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

         $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
            'reason' => 'required|string|max:500',
            'desc' => 'required|string|max:2000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $room = Room::where('id', (int) $request->room_id)->first();
        if (!$room) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        $user = User::where('is_block', 0)
            ->where('id', (int) $request->user_id)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomUser = $this->findActorRoomMembership((int) $request->room_id, (int) $request->user_id, $companyActor);
        if (!$roomUser) {
            return response()->json([
                'status' => false,
                'message' => 'You can report only rooms you are in.',
                'error_code' => 'room_report_not_member',
            ], 403);
        }

        $report = new Report();
        $report->type = 0;
        $report->room_id = (int) $request->room_id;
        $report->user_id = (int) $request->user_id;
        $report->reason = InputSanitizer::sanitizeText($request->reason, 500);
        $report->desc = InputSanitizer::sanitizeText($request->desc, 2000);
        $report->save();

        return response()->json([
            'status' => true,
            'message' => 'Report Added Successfully',
            'data' => $report,
        ]);
    }

    public function deleteReport(Request $request)
    {
        $report = Report::where('id', $request->report_id)->first();

        if ($report) {
            $roomReports = Report::where('room_id', $report->room_id)->get();
            $roomReports->each->delete();

            return response()->json([
                'status' => true,
                'message' => 'Report Delete Successfully',
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Report Not Found',
            ]);
        }
    }

    public function deleteReportWithRoom(Request $request)
    {
        $report = Report::where('id', $request->report_RoomId)->first();

        if (!$report) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }
         
        Report::where('room_id', $report->room_id)->delete();

        RoomUser::where('room_id', $report->room_id)->delete();

        SavedNotification::where('room_id', $report->room_id)->delete();

        GlobalFunction::deleteFile($report->room->photo);

        Room::where('id', $report->room_id)->delete();

        return response()->json([
            'status' => true,
            'message' => 'Room Delete Successfully.',
        ]);
    }

    public function leaveThisRoom(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomId = Room::where('id', $request->room_id)->first();

        if ($roomId == null) {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }

        $leaveRoom = RoomUser::where('room_id', $request->room_id)->where('user_id', $request->user_id);
        $this->applyRoomUserActorScope($leaveRoom, $companyActor);
        $leaveRoom = $leaveRoom->first();

        if($leaveRoom) {

            $roomId->total_member = max(0, $roomId->total_member - 1);
            $roomId->save();

            // $leaveRoom->type = -1;
            // $leaveRoom->save();

            $leaveRoom->delete();

            $userNotification = SavedNotification::where('room_id', $request->room_id)
                                    ->where('user_id', $request->user_id)
                                    ->where('type', Constants::notificationTypeAcceptRoomRequest)
                                    ->first();
            if ($userNotification != null) {
                $userNotification->delete();
            }


            return response()->json([
                'status' => true,
                'message' => 'Leave This Room',
                'data' => $leaveRoom,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Room user data not found',
            ]);
        }

    }

    public function fetchRoomDetail(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomDetails = Room::with('company')->where('id', $request->room_id)->first();
        if ($roomDetails) {
            $userRoomStatus = RoomUser::where('user_id', $request->user_id)->where('room_id', $request->room_id);
            $this->applyRoomUserActorScope($userRoomStatus, $companyActor);
            $userRoomStatus = $userRoomStatus->first();
            if($userRoomStatus) {
                $roomDetails->userRoomStatus = $userRoomStatus->type;
                $roomDetails->is_mute = $userRoomStatus->is_mute;

                $allInterests = Interest::whereIn('id', explode(',', $roomDetails->interest_ids))->get();

                if ($request->should_show_member == 1) {
                    $roomUsers = RoomUser::where('room_id', $request->room_id)
                                        ->where(function ($query) {
                                            $query->where('type', 2)
                                                ->orWhere('type', 3)
                                                ->orWhere('type', 5)
                                                ->orWhere('type', -1);
                                            })
                                            ->with(['user', 'company'])
                                            ->get();

                    //  $roomDetails->total_member = $roomUsers->count();
                     $roomDetails->roomUsers = $roomUsers;
                }

                $roomDetails->interests = $allInterests;
                $admin = User::where('id', $roomDetails->admin_id)->first();
                $roomDetails->admin = $admin;
                return response()->json([
                    'status' => true,
                    'message' => 'Room Details',
                    'data' => $roomDetails,
                ]);
             } else {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ]);
             }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Room not found',
            ]);
        }
    }

    public function deleteRoom(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $roomUser = RoomUser::where('room_id', $request->room_id)->where('user_id', $request->user_id)->where('type', 5);
        $this->applyRoomUserActorScope($roomUser, $companyActor);
        $roomUser = $roomUser->first();
        if (!$roomUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
        
        $room = Room::where('id', $request->room_id)->first();
        if (!$room) {
            return response()->json([
                'status' => false,
                'message' => 'Room not found',
            ]);
        }

        GlobalFunction::deleteFile($room->photo);
        RoomUser::where('room_id', $request->room_id)->delete();
        SavedNotification::where('room_id', $request->room_id)->delete();
        Report::where('room_id', $request->room_id)->where('type', 0)->delete();

        $room->delete();

        return response()->json([
            'status' => true,
            'message' => 'Room deleted successfully',
            'data' => $room,
        ]);
    }

    public function fetchMyOwnRooms(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $user = User::where('id', $request->my_user_id)->first();
        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }

            $room = Room::with('company')->where('admin_id', $user->id);
            if ($companyActor) {
                $room->where('company_id', $companyActor->id);
            } else {
                $room->where(function ($query) {
                    $query->whereNull('company_id')
                        ->orWhere('company_id', 0);
                });
            }
            $room = $room->get();

            return response()->json([
                'status' => true,
                'message' => 'Room you own',
                'data' => $room,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);
    }

    public function fetchRoomsByInterest(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'interest_id' => 'required',
            'user_id' => 'required',
            'start' => 'required',
            'limit' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $setting = Setting::first();
        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $rooms = Room::whereRelation('user', 'is_block', 0)
                    ->with('company')
                    ->whereRaw('find_in_set(?, interest_ids)', [(int) $request->interest_id])
                    ->where('is_private', 0)
                    ->where('total_member', '<>', (int) $setting->setRoomUsersLimit)
                    ->offset($request->start)
                    ->limit($request->limit)
                    ->get();

        if (!$rooms->isEmpty()) {
            foreach ($rooms as $room) {
                $roomUser = RoomUser::where('user_id', $request->user_id)->where('room_id', $room->id);
                $this->applyRoomUserActorScope($roomUser, $companyActor);
                $roomUser = $roomUser->first();
                if ($roomUser) {
                    $room->userRoomStatus = $roomUser->type;
                } else {
                    $room->userRoomStatus = 0;
                }
            }
        }


        return response()->json([
            'status' => true,
            'message' => 'Room Result',
            'data' => $rooms,
        ]);
    }

    public function fetchSuggestedRooms(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $rooms = app(RoomSuggestionService::class)->forViewer($user, $companyActor, 2);

        return response()->json([
            'status' => true,
            'message' => 'Suggested Room',
            'data' => $rooms,
        ]);
    }

    public function searchUserForInvitation(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
            'room_id' => 'required',
            'start' => 'required',
            'limit' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
                    ->where('id', $request->my_user_id)
                    ->first();
        if ($user != null) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }

            $room = Room::where('id', $request->room_id)->first();
            if ($room != null) {

                $blockUserIds = explode(',', $user->block_user_ids);

                $alreadyInRoomOrAcceptedUserList = RoomUser::where('room_id', $request->room_id)->pluck('user_id');

                $roomUserIds = RoomUser::where('user_id', $request->my_user_id)->where('room_id', $request->room_id);
                $this->applyRoomUserActorScope($roomUserIds, $companyActor);
                $roomUserIds = $roomUserIds->get()->pluck('user_id');

                if ($roomUserIds) {
                    $searchUser = User::where('is_block', 0)
                                    ->where('is_invited_to_room', 1)
                                    ->whereNotIn('id', $roomUserIds)
                                    ->whereNotIn('id', $alreadyInRoomOrAcceptedUserList)
                                    ->where(function ($query) use ($request) {
                                        $query
                                            ->where('username', 'like', '%' . $request->keyword . '%')
                                            ->orWhere('full_name', 'like', '%' . $request->keyword . '%');
                                    })
                                    ->whereNotIn('id', $blockUserIds)
                                    ->offset($request->start)
                                    ->limit($request->limit)
                                    ->get();

                    return response()->json([
                        'status' => true,
                        'message' => 'User result',
                        'data' => $searchUser,
                    ]);
                } else {
                       return response()->json([
                        'status' => false,
                        'message' => 'User not found',
                    ]);
                }
            }
            return response()->json([
                'status' => false,
                'message' => 'Room not found',
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User Not Found',
        ]);
    }

    public function fetchRandomRooms(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'limit' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
                    ->where('id', $request->user_id)
                    ->first();
        if($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }


            $blockUserIds = User::where('is_block', 1)->pluck('id');

            $rooms = Room::with('company')
                        ->whereNotIn('admin_id', $blockUserIds)
                        ->where('is_private', 0)
                        // ->where('admin_id', '!=', $request->user_id)
                        ->inRandomOrder()
                        ->limit($request->limit)
                        ->get();


            if(!$rooms->isEmpty()) {

                foreach ($rooms as $room) {
                    $roomUser = RoomUser::where('user_id', $request->user_id)->where('room_id', $room->id);
                    $this->applyRoomUserActorScope($roomUser, $companyActor);
                    $roomUser = $roomUser->first();
                    if($roomUser){
                        $room->userRoomStatus = $roomUser->type;
                    } else {
                        $room->userRoomStatus = 0;
                    }

                    // $total_member = RoomUser::whereNotIn('user_id', $blockUserIds)->where('room_id', $room->id)->whereIn('type', 2)->count();
                    // $room->total_member = $total_member;
                }
            }
            return response()->json([
                'status' => true,
                'message' => 'Random Room List',
                'data' => $rooms,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found'
        ]);
    }

    public function makeRoomAdmin(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $room = Room::where('id', (int) $request->room_id)->first();
        if (!$room) {
            return response()->json([
                'status' => false,
                'message' => 'Room not found',
            ]);
        }

        if ($permissionError = $this->ensureActorRoomPermission(
            (int) $room->id,
            (int) $request->my_user_id,
            $actorContext['company'],
            [5],
            'Only room owner can promote room admins.',
            'room_admin_promotion_forbidden'
        )) {
            return $permissionError;
        }

        $user = User::where('id', (int) $request->user_id)->first();
        if($user) {
            $roomUser = RoomUser::where('room_id', (int) $request->room_id)->where('user_id', (int) $request->user_id)->first();
            if($roomUser) {
                if ((int) $roomUser->type === 5) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Room owner role cannot be changed.',
                    ]);
                }

                if ((int) $roomUser->type === 3) {
                    return response()->json([
                        'status' => false,
                        'message' => 'User is already a co-admin.',
                    ]);
                }

                $roomUser->type = 3;
                $roomUser->save();
                return response()->json([
                    'status' => true,
                    'message' => 'Co\'admin created succesfully',
                    'data' => $roomUser,
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Room user not found',
                'data' => $roomUser,
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);

    }

    public function fetchRoomAdmins(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $room = Room::where('id', (int) $request->room_id)->first();
        if($room) {
            if ((int) $room->is_private === 1) {
                if ($permissionError = $this->ensureActorRoomPermission(
                    (int) $room->id,
                    (int) $request->my_user_id,
                    $actorContext['company'],
                    [2, 3, 5],
                    'You are not allowed to view room admins.',
                    'room_admins_forbidden'
                )) {
                    return $permissionError;
                }
            }

            $roomAdmins = RoomUser::where('room_id', (int) $request->room_id)->whereIn('type', [3, 5])->with(['user', 'company'])->get();
            return response()->json([
                'status' => true,
                'message' => 'Fetch room admins',
                'data' => $roomAdmins,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Room not found',
        ]);
    }

    public function removeAdminFromRoom(Request $request)
    {
        $actorContext = $this->resolveAuthenticatedRoomActor($request);
        if ($actorContext instanceof JsonResponse) {
            return $actorContext;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $room = Room::where('id', (int) $request->room_id)->first();
        if ($room) {
            if ($permissionError = $this->ensureActorRoomPermission(
                (int) $room->id,
                (int) $request->my_user_id,
                $actorContext['company'],
                [5],
                'Only room owner can remove room admins.',
                'room_admin_removal_forbidden'
            )) {
                return $permissionError;
            }

            if ((int) $request->user_id === (int) $room->admin_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Room owner cannot be demoted.',
                ]);
            }

            $roomAdmin = RoomUser::where('room_id', (int) $request->room_id)->where('user_id', (int) $request->user_id)->where('type', 3)->first();
            if($roomAdmin) {
                $roomAdmin->type = 2;
                $roomAdmin->save();

                return response()->json([
                    'status' => true,
                    'message' => 'Remove admin from room',
                    'data' => $roomAdmin,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Already User not co-admin',
                ]);
            }

        }
        return response()->json([
            'status' => false,
            'message' => 'Room not found',
        ]);

    }

    public function fetchRoomsList(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof JsonResponse) {
            return $companyActor;
        }

        $rooms = RoomUser::with(['room.company', 'user', 'company'])->where('user_id', $request->user_id)
                        ->where(function($query){
                            $query->where('type', 2)
                                  ->orWhere('type', 3)
                                  ->orWhere('type', 5);
                            });
        $this->applyRoomUserActorScope($rooms, $companyActor);
        $rooms = $rooms->get();
        return response()->json([
            'status' => true,
            'message' => 'Fetching rooms list Successfully',
            'data' => $rooms,
        ]);

    }

    public function rooms()
    {
         return view('rooms');
    }

    public function roomsListWeb(Request $request)
    {
        $totalData = Room::count();
        $rows = Room::orderBy('id', 'DESC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'image',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = Room::offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = Room::with(['user', 'roomUser'])
                            ->Where('title', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = $result->count();
        }
        $data = [];

        foreach ($result as $item) {

            if ($item->photo == null) {
                $image = '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->photo . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            $adminName = '<a href="usersDetail/'. $item->admin_id .'">'.  $item->user->full_name .'</a>';

            if ($item->is_private == 1) {
                $private = '<label class="switch"><input type="checkbox" name="private" rel="' . $item->id . '" value="' . $item->is_private . '" id="private" class="private " checked ><span class="slider"></span> </label>';
            } else {
                $private = '<label class="switch"><input type="checkbox" name="private" rel="' . $item->id . '" value="' . $item->is_private . '" id="private" class="private"><span class="slider"></span> </label>';
            }

            if ($item->is_join_request_enable == 1) {
                $join_request = '<label class="switch"><input type="checkbox" name="is_join_request_enable" rel="' . $item->id . '" value="' . $item->is_join_request_enable . '" id="is_join_request_enable" class="is_join_request_enable" checked ><span class="slider"></span> </label>';
            } else {
                $join_request = '<label class="switch"><input type="checkbox" name="is_join_request_enable" rel="' . $item->id . '" value="' . $item->is_join_request_enable . '" id="is_join_request_enable" class="is_join_request_enable"><span class="slider"></span> </label>';
            }

            $view = '<a href="./roomDetails/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View Room">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>') . '</a>';
            $delete = '<a href="#" class="ms-3 btn btn-danger px-4 text-white delete deleteRoomByAdmin d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Room">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' .  $view . $delete .' </span>';

            $data[] = [
                $image,
                $item->title,
                $adminName,
                $item->total_member,
                $join_request,
                $private,
                $action
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function updatePrivateStatus(Request $request)
    {
        $room = Room::where('id', $request->id)->first();
        if ($room) {
            $room->is_private = $request->is_private;
            $room->save();

            return response()->json([
                'status' => true,
                'message' => 'Room Status Updated successfully',
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Room not found',
        ]);
    }

    public function updateJoinRequestStatus(Request $request)
    {
        $room = Room::where('id', $request->id)->first();
        if ($room) {
            $room->is_join_request_enable = $request->is_join_request_enable;
            $room->save();

            return response()->json([
                'status' => true,
                'message' => 'Room Status Updated successfully',
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Room not found',
        ]);
    }

    public function roomDetails(Request $request)
    {
        $room = Room::where('id', $request->id)->first();
        $interests = Interest::get();
        if ($room) {
            return view('roomDetails', [
                'room' => $room,
                'interests' => $interests,
            ]);
        }
    }

    public function allRoomUsersListTableWeb(Request $request)
    {
        $totalData = RoomUser::where('room_id', $request->room_id)
                                ->where(function($query){
                                    $query->where('type', 2)
                                    ->orWhere('type', 3)
                                    ->orWhere('type', 5);
                                })->count();
        $rows = RoomUser::where('room_id', $request->room_id)->where(function($query){
                            $query->where('type', 2)
                            ->orWhere('type', 3)
                            ->orWhere('type', 5);
                        })->orderBy('type', 'ASC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'admin_id',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = RoomUser::where('room_id', $request->room_id)->with('user')->where(function($query){
                                    $query->where('type', 2)
                                    ->orWhere('type', 3)
                                    ->orWhere('type', 5);
                                })
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = RoomUser::where('room_id', $request->room_id)->with('user')->where(function($query){
                                    $query->where('type', 2)
                                    ->orWhere('type', 3)
                                    ->orWhere('type', 5);
                                })
                            ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = RoomUser::where('room_id', $request->room_id)
                                    ->where(function($query){
                                        $query->where('type', 2)
                                        ->orWhere('type', 3)
                                        ->orWhere('type', 5);
                                    })
                                    ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                                    ->count();
        }
        $data = [];

        foreach ($result as $item) {

            $user = '<a href="../usersDetail/'. $item->user->id .'" class="userLink"> '. $item->user->full_name .' </a>';

            if ($item->type == 2) {
                $typeOfMember = '<span class="type-badge badge rounded bg-warning text-white fs-6 fw-medium w-20"> Member </span>';
            } elseif ($item->type == 3) {
                $typeOfMember = '<span class="type-badge badge rounded bg-info text-white fs-6 fw-medium w-20"> Co - Member </span>';
            } elseif ($item->type == 5) {
                $typeOfMember = '<span class="type-badge badge rounded bg-success text-white fs-6 fw-medium w-20"> Admin </span>';
            }


            $data[] = [
                $user,
                $typeOfMember,
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function roomMembersListTableWeb(Request $request)
    {
        $totalData = RoomUser::where('room_id', $request->room_id)->where('type', 2)->count();
        $rows = RoomUser::where('room_id', $request->room_id)->where('type', 2)->orderBy('type', 'ASC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'admin_id',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = RoomUser::where('room_id', $request->room_id)
                            ->where('type', 2)
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = RoomUser::where('room_id', $request->room_id)
                            ->where('type', 2)
                            ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = RoomUser::where('room_id', $request->room_id)
                                    ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                                    ->where('type', 2)
                                    ->count();
        }
        $data = [];

        foreach ($result as $item) {

            $user = '<a href="../usersDetail/'. $item->user->id .'" class="userLink"> '. $item->user->full_name .' </a>';
            $typeOfMember = '<span class="type-badge badge rounded bg-warning text-white fs-6 fw-medium w-20"> Member </span>';

            $data[] = [
                $user,
                $typeOfMember,
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function roomCoAdminTableWeb(Request $request)
    {
        $totalData = RoomUser::where('room_id', $request->room_id)->where('type', 3)->count();
        $rows = RoomUser::where('room_id', $request->room_id)->where('type', 3)->orderBy('type', 'ASC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'admin_id',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = RoomUser::where('room_id', $request->room_id)
                            ->where('type', 3)
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = RoomUser::where('room_id', $request->room_id)
                            ->where('type', 3)
                            ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = RoomUser::where('room_id', $request->room_id)
                                    ->where('type', 3)
                                    ->WhereRelation('user', 'full_name', 'LIKE', "%{$search}%")
                                    ->count();
        }
        $data = [];

        foreach ($result as $item) {

            $user = '<a href="../usersDetail/'. $item->user->id .'" class="userLink"> '. $item->user->full_name .' </a>';
            $typeOfMember = '<span class="type-badge badge rounded bg-info text-white fs-6 fw-medium w-20"> Co - Admin </span>';

            $data[] = [
                $user,
                $typeOfMember,
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function userRoomsOwnTable(Request $request)
    {
        $totalData = Room::where('admin_id', $request->user_id)
                        ->count();
        $rows = Room::where('admin_id', $request->user_id)
                    ->orderBy('id', 'DESC')
                    ->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'image',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;

        if (empty($request->input('search.value'))) {
            $result = Room::where('admin_id', $request->user_id)
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = Room::where('admin_id', $request->user_id)
                            ->with()
                            ->with(['user','roomUser'])
                            ->Where('title', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = Room::where('admin_id', $request->user_id)
                                    ->Where('title', 'LIKE', "%{$search}%")
                                    ->count();
        }
        $data = [];
        foreach ($result as $item) {

            if ($item->photo == null) {
                $image = '<img src="../asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->photo . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            if ($item->is_private == 1) {
                $private = '<label class="switch"><input type="checkbox" name="private" rel="' . $item->id . '" value="' . $item->is_private . '" id="private" class="private " checked ><span class="slider"></span> </label>';
            } else {
                $private = '<label class="switch"><input type="checkbox" name="private" rel="' . $item->id . '" value="' . $item->is_private . '" id="private" class="private"><span class="slider"></span> </label>';
            }


            if ($item->is_join_request_enable == 1) {
                $join_request = '<label class="switch"><input type="checkbox" name="is_join_request_enable" rel="' . $item->id . '" value="' . $item->is_join_request_enable . '" id="is_join_request_enable" class="is_join_request_enable" checked ><span class="slider"></span> </label>';
            } else {
                $join_request = '<label class="switch"><input type="checkbox" name="is_join_request_enable" rel="' . $item->id . '" value="' . $item->is_join_request_enable . '" id="is_join_request_enable" class="is_join_request_enable"><span class="slider"></span> </label>';
            }

            $view = '<a href="../roomDetails/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>') . '</a>';
            $delete = '<a href="#" class="ms-3 btn btn-danger px-4 text-white delete deleteRoomByAdmin d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Room">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' .  $view . $delete . ' </span>';

            $data[] = [
                $image,
                $item->title,
                $item->roomUser->count(),
                $join_request,
                $private,
                $action
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function userRoomInTable(Request $request)
    {
        $totalData = RoomUser::where('user_id', $request->user_id)->where('type', '!=', Constants::invitedForRoom)->count();
        $rows = RoomUser::where('user_id', $request->user_id)
                    ->where('type', '!=', Constants::invitedForRoom)
                    ->orderBy('id', 'DESC')
                    ->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'image',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;

        if (empty($request->input('search.value'))) {
            $result = RoomUser::where('user_id', $request->user_id)
                            ->where('type', '!=', Constants::invitedForRoom)
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
        } else {
            $search = $request->input('search.value');
            $result = RoomUser::where('user_id', $request->user_id)
                            ->where('type', '!=', Constants::invitedForRoom)
                            ->with('room')
                            ->Where('title', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order, $dir)
                            ->get();
            $totalFiltered = RoomUser::where('user_id', $request->user_id)
                                    ->where('type', '!=', Constants::invitedForRoom)
                                    ->Where('title', 'LIKE', "%{$search}%")
                                    ->count();
        }
        $data = [];
        foreach ($result as $item) {

            if ($item->room->photo == null) {
                $image = '<img src="../asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->room->photo . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }
            if ($item->type == 1) {
                $typeOfMember = '<span class="type-badge badge rounded bg-warning text-white fs-6 fw-medium w-20"> Requested </span>';
            } elseif ($item->type == 2) {
                $typeOfMember = '<span class="type-badge badge rounded bg-warning text-white fs-6 fw-medium w-20"> Member </span>';
            } elseif ($item->type == 3) {
                $typeOfMember = '<span class="type-badge badge rounded bg-info text-white fs-6 fw-medium w-20"> Co - Member </span>';
            } elseif ($item->type == 5) {
                $typeOfMember = '<span class="type-badge badge rounded bg-success text-white fs-6 fw-medium w-20"> Admin </span>';
            } else {
                $typeOfMember = '<span class="type-badge badge rounded bg-success text-white fs-6 fw-medium w-20"> -1 </span>';
            }

            $view = '<a href="../roomDetails/' . $item->room->id . '" data-title="' . $item->room->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->room->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>') . '</a>';
            $delete = '<a href="#" class="ms-3 btn btn-danger px-4 text-white delete deleteRoomByAdmin d-flex align-items-center" rel="' . $item->room->id . '" data-tooltip="Delete Room">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' .  $view . $delete . ' </span>';

            $data[] = [
                $image,
                $item->room->title,
                $typeOfMember,
                $action
            ];
        }
        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
        echo json_encode($json_data);
        exit();
    }

    public function deleteThisRoom(Request $request)
    {
        $room = Room::where('id', $request->room_id)->first();

        if ($room) {

            Report::where('room_id', $request->room_id)->delete();

            RoomUser::where('room_id', $request->room_id)->delete();

            SavedNotification::where('room_id', $request->room_id)->delete();

            GlobalFunction::deleteFile($room->photo);

            $room->delete();

            return response()->json([
                'status' => true,
                'message' => 'Room Delete Successfully',
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Room Not Found',
            ]);
        }
    }

    public function muteUnmuteRoomNotification(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        $room = Room::where('id', $request->room_id)->first();

        if ($user != null && $room != null) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }


            $roomUser = RoomUser::where('room_id', $request->room_id)
                                ->where('user_id', $request->user_id);
            $this->applyRoomUserActorScope($roomUser, $companyActor);
            $roomUser = $roomUser->first();
            if (!$roomUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'Room user data not found',
                ]);
            }
            
            $roomUser->is_mute = (int) $request->is_mute;
            $roomUser->save();

            return response()->json([
                'status' => true,
                'message' => 'Mute Unmute Successfully',
                'data' => $roomUser,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Something went wrong',
        ]);

    }
    
    public function fetchRoomsIAmIn(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof JsonResponse) {
                return $companyActor;
            }

            $userInRooms = RoomUser::with(['room.company', 'company'])->where('user_id', $request->user_id);
            $this->applyRoomUserActorScope($userInRooms, $companyActor);
            $userInRooms = $userInRooms->get();

            return response()->json([
                'status' => true,
                'message' => 'Users In Room List',
                'data' => $userInRooms,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User Not Found',
        ]);

    }



}
