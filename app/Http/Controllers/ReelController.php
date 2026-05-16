<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Company;
use App\Models\Constants;
use App\Models\FollowingList;
use App\Models\GlobalFunction;
use App\Models\Interest;
use App\Models\Like;
use App\Models\Music;
use App\Models\MusicCategory;
use App\Models\Reel;
use App\Models\LikeReelComment;
use App\Models\ReelComment;
use App\Models\Report;
use App\Models\SavedNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReelController extends Controller
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
                'message' => 'You do not own this company.',
            ]);
        }

        return $company;
    }

    private function actorDisplayName(User $user, $companyActor = null): string
    {
        return $companyActor ? $companyActor->name : $user->full_name;
    }

    private function adminActorUsername($item): string
    {
        return $item->company ? 'company-' . $item->company->id : ($item->user->username ?? '');
    }

    private function adminActorName($item): string
    {
        return $item->company ? $item->company->name : ($item->user->full_name ?? '');
    }

    private function adminActorProfile($item): string
    {
        $profile = $item->company ? $item->company->logo : ($item->user->profile ?? null);
        return $profile ?: asset('asset/img/default.png');
    }

    private function adminActorUserId($item): int
    {
        return (int) ($item->company->owner_user_id ?? $item->user_id ?? $item->user->id ?? 0);
    }

    private function adminActorLink($item): string
    {
        $userId = $this->adminActorUserId($item);
        $name = e($this->adminActorName($item));
        $href = $userId > 0 ? 'usersDetail/' . $userId : '#';
        $badge = $item->company
            ? '<div class="mt-1"><span class="badge bg-info">Entreprise</span><small class="d-block text-muted">' . e($this->adminActorUsername($item)) . '</small></div>'
            : '';

        return '<a href="' . $href . '">' . $name . '</a>' . $badge;
    }

    private function adminCommentActorPayload($comment): array
    {
        if ($comment->company) {
            return [
                'id' => (int) ($comment->company->owner_user_id ?? $comment->user_id),
                'username' => 'company-' . $comment->company->id,
                'name' => $comment->company->name,
                'profile' => $comment->company->logo ?: 'asset/image/default.png',
                'type' => 'company',
            ];
        }

        return [
            'id' => (int) ($comment->user->id ?? $comment->user_id),
            'username' => $comment->user->username ?? '',
            'name' => $comment->user->full_name ?? '',
            'profile' => ($comment->user->profile ?? null) ?: 'asset/image/default.png',
            'type' => 'user',
        ];
    }

    private function isSameReelActor(Reel $reel, User $user, $companyActor = null): bool
    {
        return $companyActor
            ? ((int) $reel->company_id === (int) $companyActor->id)
            : ((int) $reel->user_id === (int) $user->id && $reel->company_id === null);
    }

    private function isSameReelCommentActor(ReelComment $comment, User $user, $companyActor = null): bool
    {
        return $companyActor
            ? ((int) $comment->company_id === (int) $companyActor->id)
            : ((int) $comment->user_id === (int) $user->id && $comment->company_id === null);
    }

    public function fetchMusicWithSearch(Request $request)
    {
        $data = $request->validate([
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'keyword' => 'nullable|string',
        ]);

        $query = Music::where('is_deleted', Constants::DeletedNo);

        if (!empty($data['keyword'])) {
            $query->where(function ($q) use ($data) {
                $q->where('title', 'like', "%{$data['keyword']}%")
                ->orWhere('artist', 'like', "%{$data['keyword']}%");
            });
        }

        $musics = $query->latest('id')
                        ->skip($data['start'])
                        ->take($data['limit'])
                        ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetched music successfully.',
            'data' => $musics,
        ]);
    }

    public function fetchMusicCategories()
    {
        $musicCategories = MusicCategory::withCount('musics')
                                        ->where('is_deleted', Constants::DeletedNo)
                                        ->orderByDesc('id')
                                        ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Musics Categories Successfully.',
            'data' => $musicCategories,
        ]);
    }

    public function fetchSavedMusic(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::find($request->user_id);

        $savedMusicIds = explode(',', $user->saved_music_ids);

        $musics = Music::whereIn('id', $savedMusicIds)->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch User Saved Musics Successfully.',
            'data' => $musics,
        ]);
    }

    public function fetchMusicByCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'category_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $music = Music::where('is_deleted', Constants::DeletedNo)
                    ->where('category_id', $request->category_id)
                    ->orderByDesc('id')
                    ->offset($request->start)
                    ->limit($request->limit)
                    ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Musics By Categories Successfully.',
            'data' => $music,
        ]);
    }

    public function fetchReelsByMusic(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'music_id' => 'required|integer|exists:musics,id',
            'user_id' => 'required|integer|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $musicReels = Reel::where('music_id', $request->music_id)
                            ->with(['user', 'company'])
                            ->orderByDesc('id')
                            ->offset($request->start)
                            ->limit($request->limit)
                            ->get();
        
        $reels = Reel::processReels($musicReels, $user->id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Fetch Reels By Music Successfully.',
            'data' => $reels,
        ]);
    }
    
    public function uploadReel(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id'   => 'required|exists:users,id',
            'interest_ids'  => 'nullable|string',
            'content'   => 'required',
            'thumbnail' => 'required',
            'music_id' => 'nullable|exists:musics,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::find($request->user_id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found'
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reel = Reel::create([
            'user_id'      => $request->user_id,
            'company_id'   => $companyActor ? $companyActor->id : null,
            'description'  => InputSanitizer::sanitizeText($request->description),
            'interest_ids' => InputSanitizer::sanitizeIdList($request->interest_ids),
            'hashtags'     => InputSanitizer::sanitizeText($request->hashtags, 1000),
            'content'      => GlobalFunction::saveFileAndGivePath($request->content),
            'thumbnail'    => GlobalFunction::saveFileAndGivePath($request->thumbnail),
            'music_id'     => $request->music_id,
        ]);

        $reel = $reel->fresh(['user', 'company']);
        $actorName = $this->actorDisplayName($user, $companyActor);

        // Send notifications to mentioned users in reel description (batch loaded)
        if ($request->filled('mentioned_user_ids')) {
            $rawIds = array_filter(array_map(function ($id) use ($user) {
                $id = (int) trim($id);
                return ($id > 0 && $id != $user->id) ? $id : null;
            }, explode(',', $request->mentioned_user_ids)));

            if (!empty($rawIds)) {
                $mentionedUsers = User::whereIn('id', $rawIds)->get();
                foreach ($mentionedUsers as $mentionedUser) {
                    if ($mentionedUser->is_push_notifications == Constants::pushNotification) {
                        $notificationDesc = $actorName . ' mentioned you in a reel.';
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $mentionedUser->device_token, $mentionedUser->device_type, [
                            'type' => Constants::notificationTypeMentionInReel,
                            'reel_id' => $reel->id,
                        ]);
                    }
                    SavedNotification::create([
                        'my_user_id' => (int) $mentionedUser->id,
                        'user_id' => (int) $user->id,
                        'company_id' => $companyActor ? (int) $companyActor->id : null,
                        'reel_id' => (int) $reel->id,
                        'type' => Constants::notificationTypeMentionInReel,
                    ]);
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Reel Added Successfully.',
            'data' => $reel,
        ]);
    }

    public function likeDislikeReel(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reel_id' => 'required|exists:reels,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found.'
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reel = Reel::with(['user', 'company'])->find($request->reel_id);
        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Not Found.'
            ]);
        }

        $likeRecord = Like::where('user_id', $user->id)
            ->where('reel_id', $request->reel_id)
            ->where('company_id', $companyActor ? $companyActor->id : null)
            ->first();
        $type = Constants::notificationTypeReelLike;
        $sameActorReel = $this->isSameReelActor($reel, $user, $companyActor);
        $actorName = $this->actorDisplayName($user, $companyActor);

        if ($likeRecord) {

            $reel->likes_count = max(0, $reel->likes_count - 1);
            $reel->save();

            SavedNotification::where('user_id', $user->id)
                                ->where('reel_id', $request->reel_id)
                                ->where('type', $type)
                                ->where('company_id', $companyActor ? $companyActor->id : null)
                                ->delete();

            $likeRecord->delete();

            return response()->json([
                'status' => true,
                'message' => 'Reel Dislike Successfully.',
                'data' => $reel,
            ]);
        } else {

            Like::create([
                'user_id' => $user->id,
                'company_id' => $companyActor ? $companyActor->id : null,
                'reel_id' => $request->reel_id,
            ]);

            $reel->likes_count += 1;
            $reel->save();

            $notificationDesc = $actorName . ' has liked your Reel.';
            if (!$sameActorReel && $reel->user && $reel->user->is_push_notifications == Constants::pushNotification) {
                GlobalFunction::sendPushNotificationToUser($notificationDesc, $reel->user->device_token, $reel->user->device_type, [
                    'type' => Constants::notificationTypeReelLike,
                    'reel_id' => $request->reel_id,
                ]);
            }

            if (!$sameActorReel && $reel->user) {
                $savedNotification = new SavedNotification();
                $savedNotification->my_user_id = $reel->user->id;
                $savedNotification->user_id = $user->id;
                $savedNotification->company_id = $companyActor ? (int) $companyActor->id : null;
                $savedNotification->reel_id = $request->reel_id;
                $savedNotification->type = $type;
                $savedNotification->save();
            }

            return response()->json([
                'status' => true,
                'message' => 'Reel Liked.',
                'data' => $reel,
            ]);
        }
    }

    public function increaseReelViewCount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reel_id' => 'required|numeric|exists:reels,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $reel = Reel::find($request->reel_id);

        $reel->increment('views_count');

        return response()->json([
            'status' => true,
            'message' => 'Reel view count increased successfully.',
            'data' => [
                'reel_id' => $reel->id,
                'views_count' => $reel->views_count
            ],
        ]);
    }

    public function addReelComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'reel_id' => 'required|integer|exists:reels,id',
            'description' => 'required|string|max:500',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)
            ->where('is_block', Constants::unblocked)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found or Blocked.',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reel = Reel::with(['user', 'company'])->find($request->reel_id);
        $actorName = $this->actorDisplayName($user, $companyActor);
        $sameActorReel = $this->isSameReelActor($reel, $user, $companyActor);

        $createData = [
            'user_id' => $user->id,
            'company_id' => $companyActor ? $companyActor->id : null,
            'reel_id' => $reel->id,
            'description' => InputSanitizer::sanitizeText($request->description, 500),
        ];
        if ($request->filled('parent_id')) {
            $createData['parent_id'] = (int) $request->parent_id;
        }
        $reelComment = ReelComment::create($createData);

        $reel->increment('comments_count');

        if (!$sameActorReel && $reel->user) {
            $reelOwner = $reel->user;
            if ($reelOwner->is_push_notifications == Constants::pushNotification) {
                $notificationMessage = "{$actorName} has commented on your Reel: {$request->description}";
                GlobalFunction::sendPushNotificationToUser($notificationMessage, $reelOwner->device_token, $reelOwner->device_type, [
                    'type' => Constants::notificationTypeAddReelComment,
                    'reel_id' => $reel->id,
                ]);
            }

            SavedNotification::create([
                'my_user_id' => $reelOwner->id,
                'user_id' => $user->id,
                'company_id' => $companyActor ? (int) $companyActor->id : null,
                'reel_id' => (int) $reel->id,
                'reel_comment_id' => $reelComment->id,
                'type' => Constants::notificationTypeAddReelComment,
            ]);
        }

        // Notify the parent comment author when replying
        if ($request->filled('parent_id')) {
            $parentComment = ReelComment::with('user')->find($request->parent_id);
            if ($parentComment && !$this->isSameReelCommentActor($parentComment, $user, $companyActor)) {
                $parentAuthor = User::find($parentComment->user_id);
                if ($parentAuthor && $parentAuthor->is_push_notifications == Constants::pushNotification) {
                    $notificationDesc = $actorName . ' replied to your comment.';
                    GlobalFunction::sendPushNotificationToUser($notificationDesc, $parentAuthor->device_token, $parentAuthor->device_type, [
                        'type' => Constants::notificationTypeAddReelComment,
                        'reel_id' => $reel->id,
                    ]);
                }
                SavedNotification::create([
                    'my_user_id' => $parentComment->user_id,
                    'user_id' => (int) $user->id,
                    'company_id' => $companyActor ? (int) $companyActor->id : null,
                    'reel_id' => (int) $reel->id,
                    'reel_comment_id' => (int) $reelComment->id,
                    'type' => Constants::notificationTypeAddReelComment,
                ]);
            }
        }

        $reelComment->load(['user', 'company']);

        // Send notifications to mentioned users in reel comment (batch loaded)
        if ($request->filled('mentioned_user_ids')) {
            $rawIds = array_filter(array_map(function ($id) use ($user) {
                $id = (int) trim($id);
                return ($id > 0 && $id != $user->id) ? $id : null;
            }, explode(',', $request->mentioned_user_ids)));

            if (!empty($rawIds)) {
                $mentionedUsers = User::whereIn('id', $rawIds)->get();
                foreach ($mentionedUsers as $mentionedUser) {
                    if ($mentionedUser->is_push_notifications == Constants::pushNotification) {
                        $notificationDesc = $actorName . ' mentioned you in a reel comment.';
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $mentionedUser->device_token, $mentionedUser->device_type, [
                            'type' => Constants::notificationTypeMentionInReelComment,
                            'reel_id' => $reel->id,
                        ]);
                    }
                    SavedNotification::create([
                        'my_user_id' => (int) $mentionedUser->id,
                        'user_id' => (int) $user->id,
                        'company_id' => $companyActor ? (int) $companyActor->id : null,
                        'reel_id' => (int) $reelComment->reel_id,
                        'reel_comment_id' => (int) $reelComment->id,
                        'type' => Constants::notificationTypeMentionInReelComment,
                    ]);
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Reel Comment Added Successfully.',
            'data' => $reelComment,
        ]);
    }

    public function fetchReelComments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'reel_id' => 'required|integer|exists:reels,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

        $reelComments = ReelComment::where('reel_id', $request->reel_id)
            ->whereNull('parent_id')
            ->whereNotIn('user_id', $blockUserIds)
            ->with([
                'user' => function ($query) {
                    $query->where('is_block', Constants::unblocked);
                },
                'company',
                'likes' => function ($query) use ($request, $companyActor) {
                    $query->where('user_id', $request->user_id)
                        ->where('company_id', $companyActor ? $companyActor->id : null);
                }
            ])
            ->withCount('likes as comment_like_count')
            ->withCount('replies as reply_count')
            ->orderByDesc('id')
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        foreach ($reelComments as $reelComment) {
            $reelComment->is_like = $reelComment->likes->isNotEmpty() ? 1 : 0;
        }

        return response()->json([
            'status' => true,
            'message' => 'Reel Comment Fetch Successfully.',
            'data' => $reelComments,
        ]);
    }

    public function fetchReelCommentReplies(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reel_comment_id' => 'required',
            'user_id' => 'required',
            'start' => 'required',
            'limit' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $replies = ReelComment::where('parent_id', $request->reel_comment_id)
            ->with([
                'user' => function ($query) {
                    $query->where('is_block', Constants::unblocked);
                },
                'company',
                'likes' => function ($query) use ($request, $companyActor) {
                    $query->where('user_id', $request->user_id)
                        ->where('company_id', $companyActor ? $companyActor->id : null);
                }
            ])
            ->withCount('likes as comment_like_count')
            ->withCount('replies as reply_count')
            ->orderBy('id', 'ASC')
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        foreach ($replies as $reply) {
            $reply->is_like = $reply->likes->isNotEmpty() ? 1 : 0;
        }

        return response()->json([
            'status' => true,
            'message' => 'Fetch Reel Comment Replies',
            'data' => $replies,
        ]);
    }

    public function likeDislikeReelComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'comment_id' => 'required|integer|exists:reel_comments,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $existing = LikeReelComment::where('user_id', $user->id)
            ->where('reel_comment_id', $request->comment_id)
            ->where('company_id', $companyActor ? $companyActor->id : null)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'status' => true,
                'message' => 'Dislike Reel Comment Successfully.',
                'data' => $existing
            ]);
        } else {
            $like = LikeReelComment::create([
                'user_id' => (int) $user->id,
                'company_id' => $companyActor ? (int) $companyActor->id : null,
                'reel_comment_id' => (int) $request->comment_id,
            ]);
            return response()->json([
                'status' => true,
                'message' => 'Like Reel Comment Successfully.',
                'data' => $like
            ]);
        }
    }

    public function editReelComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'comment_id' => 'required|integer|exists:reel_comments,id',
            'description' => 'required|string|max:500',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->my_user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reelCommentQuery = ReelComment::where('id', $request->comment_id)
            ->where('user_id', $user->id);
        if ($companyActor) {
            $reelCommentQuery->where('company_id', $companyActor->id);
        } else {
            $reelCommentQuery->whereNull('company_id');
        }

        $reelComment = $reelCommentQuery->first();

        if (!$reelComment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found or you do not have permission to edit this comment.',
            ]);
        }

        $reelComment->description = InputSanitizer::sanitizeText($request->description, 500);
        $reelComment->is_edited = 1;
        $reelComment->save();

        $reelComment->load(['user', 'company']);

        return response()->json([
            'status' => true,
            'message' => 'Reel comment updated successfully',
            'data' => $reelComment,
        ]);
    }

    public function deleteReelComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'comment_id' => 'required|integer|exists:reel_comments,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->my_user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reelCommentQuery = ReelComment::where('id', $request->comment_id)
            ->where('user_id', $user->id);
        if ($companyActor) {
            $reelCommentQuery->where('company_id', $companyActor->id);
        } else {
            $reelCommentQuery->whereNull('company_id');
        }

        $reelComment = $reelCommentQuery->first();

        if (!$reelComment) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Comment not found or you do not have permission to delete this comment.',
            ]);
        }

        $replyCount = ReelComment::where('parent_id', $reelComment->id)->count();
        ReelComment::where('parent_id', $reelComment->id)->delete();

        $reel = Reel::findOrFail($reelComment->reel_id);
        $reel->comments_count = max(0, $reel->comments_count - 1 - $replyCount);
        $reel->save();

        SavedNotification::where('user_id', $user->id)
            ->where('reel_comment_id', $request->comment_id)
            ->where('type', Constants::notificationTypeAddReelComment)
            ->where('company_id', $companyActor ? $companyActor->id : null)
            ->delete();

        $reelComment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reel Comment Delete Successfully.',
            'data' => $reelComment,
        ]);
    }

    public function fetchReelsByUserId(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|exists:users,id',
            'user_id' => 'required|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false, 
                'message' => $validator->errors()->first(),
            ]);
        }
    
        $user = User::find($request->user_id);
        $myUser = User::find($request->my_user_id);
    
        if (!$user || !$myUser) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found.',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $myUser);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }
    
        $blockUserIds = explode(',', $user->block_user_ids);
        $blockMyUserIds = explode(',', $myUser->block_user_ids);
    
        if (in_array($request->my_user_id, $blockUserIds) || in_array($request->user_id, $blockMyUserIds)) {
            return response()->json([
                'status' => false,
                'message' => 'User Blocked.',
            ]);
        }
    
        $reelsQuery = Reel::where('user_id', $request->user_id)
                          ->whereNotIn('user_id', $blockUserIds)
                          ->whereRelation('user', 'is_block', Constants::unblocked)
                          ->with(['user', 'company'])
                          ->orderByDesc('id');
    
        $reels = $reelsQuery->offset($request->start)
                             ->limit($request->limit)
                             ->get();
    
        $reels = Reel::processReels($reels, $request->my_user_id, $companyActor ? $companyActor->id : null);
    
        return response()->json([
            'status' => true,
            'message' => 'Fetch Reels Successfully On Profile.',
            'data' => $reels,
        ]);
    }
    
    public function fetchReelsOnExplore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|exists:users,id',
            'type' => 'required|numeric',
            'limit' => 'required|integer|min:1',
            'excluded_reel_ids' => 'nullable|string',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $user = User::find($request->my_user_id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $blockUserIds = explode(',', $user->block_user_ids);

        $reelsQuery = Reel::with(['user', 'company'])
            ->whereNotIn('user_id', $blockUserIds)
            ->whereRelation('user', 'is_block', Constants::unblocked);

        $excludedReelIds = array_values(array_filter(array_map('intval', explode(',', (string) $request->excluded_reel_ids))));
        if (!empty($excludedReelIds)) {
            $reelsQuery->whereNotIn('id', $excludedReelIds);
        }

        if ($request->type == Constants::forYouReel) {
            $reelsQuery = $reelsQuery->inRandomOrder();
        } elseif ($request->type == Constants::followingReel) {
            $followingUserIds = FollowingList::where('my_user_id', $request->my_user_id)
                ->when($companyActor, function ($query) use ($companyActor) {
                    $query->where('company_id', $companyActor->id);
                }, function ($query) {
                    $query->whereNull('company_id');
                })
                ->pluck('user_id')
                ->toArray();
            $reelsQuery = $reelsQuery->whereIn('user_id', $followingUserIds)->skip($request->start)->take($request->limit);
        } else {
            return response()->json(['status' => false, 'message' => 'Invalid "type" provided.']);
        }

        $reels = $reelsQuery->take($request->limit)->get();
        $reels = Reel::processReels($reels, $request->my_user_id, $companyActor ? $companyActor->id : null);

        if ($reels->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Reels Not Found.']);
        }

        return response()->json([
            'status' => true,
            'message' => 'Fetch Reels Successfully On Explore.',
            'data' => $reels,
        ]);
    }

    public function deleteReel(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reel_id' => 'required|exists:reels,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', Constants::unblocked)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found.']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reelQuery = Reel::where('id', $request->reel_id)->where('user_id', $user->id);
        if ($companyActor) {
            $reelQuery->where('company_id', $companyActor->id);
        } else {
            $reelQuery->whereNull('company_id');
        }

        $reel = $reelQuery->first();
        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Not Found.'
            ]);
        }

        $reelComments = ReelComment::where('reel_id', $request->reel_id)->get();
        foreach ($reelComments as $reelComment) {
            SavedNotification::where('reel_comment_id', $reelComment->id)
                ->whereIn('type', [
                    Constants::notificationTypeReelLike,
                    Constants::notificationTypeComment,
                    Constants::notificationTypeAddReelComment
                ])
                ->delete();
            $reelComment->delete();
        }

        Like::where('reel_id', $request->reel_id)->delete();

        GlobalFunction::deleteFile($reel->content);
        GlobalFunction::deleteFile($reel->thumbnail);

        SavedNotification::where('reel_id', $request->reel_id)
            ->whereIn('type', [
                Constants::notificationTypeReelLike,
                Constants::notificationTypeComment,
                Constants::notificationTypeAddReelComment
            ])
            ->delete();

        $reel->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reel Deleted Successfully.',
            'data' => $reel,
        ]);
    }

    public function searchReelsByInterestId(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // 'interest_id' => 'required',
            'user_id' => 'required|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }
if($request->interest_id != null) {
        $fetchInterest = Interest::find($request->interest_id);
        if (!$fetchInterest) {
            return response()->json([
                'status' => false,
                'message' => 'Interest Not Found.',
            ]);
        }
    }

        $user = User::where('is_block', Constants::unblocked)
                    ->where('id', $request->user_id)
                    ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found.',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $blockUserIds = explode(',', $user->block_user_ids);

        $reelsQuery = Reel::whereRelation('user', 'is_block', Constants::unblocked)
                        ->whereNotIn('user_id', $blockUserIds)
                        ->with(['user', 'company'])
                        ->orderByDesc('id');

                        if($request->interest_id != null) {
                            $reelsQuery->whereRaw('find_in_set(?, interest_ids)', [$request->interest_id]);
                        }

        if (!empty($request->keyword)) {
            $reelsQuery->where('description', 'like', '%' . $request->keyword . '%');
        }

        $reels = $reelsQuery->offset($request->start)
                            ->limit($request->limit)
                            ->get();

        $reels = Reel::processReels($reels, $request->user_id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Search Reels By Interest.',
            'data' => $reels,
        ]);
    }

    public function fetchReelsByHashtag(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'tag' => 'required|string',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }
    
        $user = User::where('is_block', Constants::unblocked)
                    ->where('id', $request->user_id)
                    ->first();
    
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }
    
        $blockUserIds = explode(',', $user->block_user_ids);
    
        $reelsQuery = Reel::whereRelation('user', 'is_block', Constants::unblocked)
                          ->whereNotIn('user_id', $blockUserIds)
                          ->whereRaw('find_in_set(?, hashtags)', [$request->tag])
                          ->with(['user', 'company'])
                          ->orderByDesc('id');
    
        $reels = $reelsQuery->offset($request->start)
                             ->limit($request->limit)
                             ->get();
    
        $reels = Reel::processReels($reels, $request->user_id, $companyActor ? $companyActor->id : null);
    
        return response()->json([
            'status' => true,
            'message' => 'Fetched reels by hashtag successfully.',
            'data' => $reels,
        ]);
    }
    
    public function reportReel(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'reel_id' => 'required|integer',
            'reason' => 'required|string|max:500',
            'desc' => 'required|string|max:2000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)
            ->where('is_block', Constants::unblocked)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found.',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $reel = Reel::where('id', $request->reel_id)->first();

        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Not Found',
            ]);
        }

        $report = new Report();
        $report->type = 3;
        $report->reel_id = (int) $request->reel_id;
        $report->user_id = (int) $request->user_id;
        $report->reason = InputSanitizer::sanitizeText($request->reason, 500);
        $report->desc = InputSanitizer::sanitizeText($request->desc, 2000);
        $report->save();

        return response()->json([
            'status' => true,
            'message' => 'Report Added Successfully.',
            'data' => $report,
        ]);
    }

    public function fetchSavedReels(Request $request) 
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::find($request->user_id);
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $savedReelIds = explode(',', $user->saved_reel_ids);

        $blockUserIds = explode(',', $user->block_user_ids);
      
        $reelsQuery = Reel::whereIn('id', $savedReelIds)
                          ->whereNotIn('user_id', $blockUserIds)
                          ->whereRelation('user', 'is_block', Constants::unblocked)
                          ->with(['user', 'company'])
                          ->orderByDesc('id');
    
        $reels = $reelsQuery->offset($request->start)
                             ->limit($request->limit)
                             ->get();
    
        $reels = Reel::processReels($reels, $request->user_id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Fetch Saved Reels Successfully.',
            'data' => $reels,
        ]);
    }

    public function fetchReelById(Request $request) 
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reel_id' => 'required|exists:reels,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::find($request->user_id);
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $blockUserIds = explode(',', $user->block_user_ids);
      
        $reel = Reel::where('id', $request->reel_id)
                          ->whereNotIn('user_id', $blockUserIds)
                          ->whereRelation('user', 'is_block', Constants::unblocked)
                          ->with(['user', 'company'])
                          ->get();
    
        $reelsById = Reel::processReels($reel, $request->user_id, $companyActor ? $companyActor->id : null)->first();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Reel By Id Successfully.',
            'data' => $reelsById,
        ]);
    }

    public function viewReels()
    {
        return view('viewReels');
    }

    public function reelList(Request $request)
    {
        $query = Reel::query()->with(['user', 'company', 'music']);
        $totalData = $query->count();

        $columns = 'id';
        $orderDir = 'desc';
        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $columns;
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('description', 'LIKE', "%{$searchValue}%")
                    ->orWhere('hashtags', 'LIKE', "%{$searchValue}%")
                    ->orWhereHas('user', function ($q) use ($searchValue) {
                        $q->where('full_name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('username', 'LIKE', "%{$searchValue}%");
                    })
                    ->orWhereHas('company', function ($q) use ($searchValue) {
                        $q->where('name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('email', 'LIKE', "%{$searchValue}%");
                    });
            });
        }

        $totalFiltered = $query->count();

        $result = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        $fetchInterests = Interest::get();

        $data = $result->map(function ($item) use ($fetchInterests) {

            if ($item->music_id != null) {
                $music = $item->music;
            } else {
                $music = "null";
            }

            $user = $this->adminActorLink($item);
            $thumbnailUrl = $item->thumbnail ? $item->thumbnail : asset('asset/img/default.png');

            $contentUrl = $item->content ? $item->content : asset('asset/img/default.png');

            $profileUrl = e($this->adminActorProfile($item));
            $actorUserId = $this->adminActorUserId($item);
            $actorUsername = e($this->adminActorUsername($item));
            $descriptionAttr = e($item->description ?? '');

            $description = "<div class='itemDescription'>" . e($item->description) . "</div>";

            $interest_ids = explode(',', $item->interest_ids);
            $interest_titles = [];

            foreach ($fetchInterests as $interest) {
                if (in_array($interest->id, $interest_ids)) {
                    $interest_titles[] = $interest->title;
                }
            }

            $interest_titles_string = $fetchInterests
                                    ->whereIn('id', $interest_ids)
                                    ->pluck('title')
                                    ->implode(', '); 
            $interestsAttr = e($interest_titles_string);

            $thumbnail = "<div class='reelThumbnail viewReelModal cursor-pointer' 
                        rel='{$item->id}'
                        data-content='{$contentUrl}'
                        data-description='{$descriptionAttr}'
                        data-user_id='{$actorUserId}'
                        data-user_profile='{$profileUrl}'
                        data-music='{$music}'
                        data-interests='{$interestsAttr}'
                        data-username='{$actorUsername}'>
                        <img src='{$thumbnailUrl}' alt='table-user' class='object-fit-cover rounded border img-fluid'>
                        <svg viewBox='0 0 24 24' width='24' height='24' stroke='currentColor' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round' class='css-i6dzq1'><circle cx='12' cy='12' r='10'></circle><polygon points='10 8 16 12 10 16 10 8'></polygon></svg>
                    </div>";

            $counts = "<div class='counts-div'>
                            <span class=''>" . __('Likes') . " <b>: {$item->likes_count} </b></span>
                            <br>
                            <span class=''>" . __('Comments') . " <b>: {$item->comments_count} </b></span>
                            <br>
                            <span class=''>" . __('Views') . " <b>: {$item->views_count} </b></span>
                        </div>";

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteReelByAdmin d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Reel">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';

            $action = "<span class='d-flex justify-content-start align-items-center'>{$delete}</span>";

            return [
                $thumbnail,
                $description,
                $item->hashtags,
                $counts,
                $user,
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    public function userReelList(Request $request)
    {
        $query = Reel::where('user_id', $request->userId)->with(['user', 'company', 'music']);
        $totalData = $query->count();

        $columns = 'id';
        $orderDir = 'desc';
        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $columns;
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('description', 'LIKE', "%{$searchValue}%")
                    ->orWhere('hashtags', 'LIKE', "%{$searchValue}%")
                    ->orWhereHas('user', function ($q) use ($searchValue) {
                        $q->where('full_name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('username', 'LIKE', "%{$searchValue}%");
                    })
                    ->orWhereHas('company', function ($q) use ($searchValue) {
                        $q->where('name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('email', 'LIKE', "%{$searchValue}%");
                    });
            });
        }

        $totalFiltered = $query->count();

        $result = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        $fetchInterests = Interest::get();

        $data = $result->map(function ($item) use ($fetchInterests) {

            if ($item->music_id != null) {
                $music = $item->music;
            } else {
                $music = "null";
            }

            $thumbnailUrl = $item->thumbnail ? $item->thumbnail : asset('asset/img/default.png');

            $contentUrl = $item->content ? $item->content : asset('asset/img/default.png');

            $profileUrl = e($this->adminActorProfile($item));
            $actorUserId = $this->adminActorUserId($item);
            $actorUsername = e($this->adminActorUsername($item));
            $descriptionAttr = e($item->description ?? '');

            $description = "<div class='itemDescription'>" . e($item->description) . "</div>";

            $interest_ids = explode(',', $item->interest_ids);
            $interest_titles = [];

            foreach ($fetchInterests as $interest) {
                if (in_array($interest->id, $interest_ids)) {
                    $interest_titles[] = $interest->title;
                }
            }

            $interest_titles_string = $fetchInterests
                                    ->whereIn('id', $interest_ids)
                                    ->pluck('title')
                                    ->implode(', '); 
            $interestsAttr = e($interest_titles_string);

            $thumbnail = "<div class='reelThumbnail viewReelModal cursor-pointer' 
                        rel='{$item->id}'
                        data-content='{$contentUrl}'
                        data-description='{$descriptionAttr}'
                        data-user_id='{$actorUserId}'
                        data-user_profile='{$profileUrl}'
                        data-music='{$music}'
                        data-interests='{$interestsAttr}'
                        data-username='{$actorUsername}'>
                        <img src='{$thumbnailUrl}' alt='table-user' class='object-fit-cover rounded border img-fluid'>
                        <svg viewBox='0 0 24 24' width='24' height='24' stroke='currentColor' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round' class='css-i6dzq1'><circle cx='12' cy='12' r='10'></circle><polygon points='10 8 16 12 10 16 10 8'></polygon></svg>
                    </div>";

            $counts = "<div class='counts-div'>
                            <span class=''>" . __('Likes') . " <b>: {$item->likes_count} </b></span>
                            <br>
                            <span class=''>" . __('Comments') . " <b>: {$item->comments_count} </b></span>
                            <br>
                            <span class=''>" . __('Views') . " <b>: {$item->views_count} </b></span>
                        </div>";

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteReelByAdmin d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Reel">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';

            $action = "<span class='d-flex justify-content-start align-items-center'>{$delete}</span>";

            return [
                $thumbnail,
                $description,
                $item->hashtags,
                $counts,
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    public function reelReportList(Request $request)
    {
        $reportType = 3;

        $query = Report::where('type', $reportType)->with(['reel.user', 'reel.company', 'reel.music']);
        $totalData = $query->count();

        $columns = 'id';
        $orderDir = 'desc';
        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $columns;
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('reason', 'LIKE', "%{$searchValue}%")
                    ->orWhere('desc', 'LIKE', "%{$searchValue}%")
                    ->orWhereHas('reel', function ($rq) use ($searchValue) {
                        $rq->where('description', 'LIKE', "%{$searchValue}%")
                            ->orWhere('hashtags', 'LIKE', "%{$searchValue}%")
                            ->orWhereHas('user', function ($uq) use ($searchValue) {
                                $uq->where('full_name', 'LIKE', "%{$searchValue}%")
                                    ->orWhere('username', 'LIKE', "%{$searchValue}%");
                            })
                            ->orWhereHas('company', function ($cq) use ($searchValue) {
                                $cq->where('name', 'LIKE', "%{$searchValue}%")
                                    ->orWhere('email', 'LIKE', "%{$searchValue}%");
                            });
                    });
            });
        }

        $totalFiltered = $query->count();

        $result = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        $fetchInterests = Interest::get();

        $data = $result->map(function ($item) use ($fetchInterests) {

            $reel = $item->reel;

            if (!$reel) {
                $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';

                return [
                    '<span class="text-muted">Reel introuvable</span>',
                    $item->reason,
                    $item->desc,
                    '<span class="float-right d-flex">' . $rejectReport . ' </span>'
                ];
            }

            if ($reel->music_id != null) {
                $music = $reel->music;
            } else {
                $music = "null";
            } 

            $thumbnailUrl = $reel->thumbnail ? $reel->thumbnail : asset('asset/img/default.png');

            $contentUrl = $reel->content ? $reel->content : asset('asset/img/default.png');

            $profileUrl = e($this->adminActorProfile($reel));
            $actorUserId = $this->adminActorUserId($reel);
            $actorUsername = e($this->adminActorUsername($reel));
            $descriptionAttr = e($reel->description ?? '');

            $interest_ids = explode(',', $reel->interest_ids);
            $interest_titles = [];

            foreach ($fetchInterests as $interest) {
                if (in_array($interest->id, $interest_ids)) {
                    $interest_titles[] = $interest->title;
                }
            }

            $interest_titles_string = $fetchInterests
                                    ->whereIn('id', $interest_ids)
                                    ->pluck('title')
                                    ->implode(', '); 
            $interestsAttr = e($interest_titles_string);

            $thumbnail = "<div class='reelThumbnail viewReelModal cursor-pointer' 
                        rel='{$reel->id}'
                        data-content='{$contentUrl}'
                        data-description='{$descriptionAttr}'
                        data-user_id='{$actorUserId}'
                        data-user_profile='{$profileUrl}'
                        data-music='{$music}'
                        data-interests='{$interestsAttr}'
                        data-username='{$actorUsername}'>
                        <img src='{$thumbnailUrl}' alt='table-user' class='object-fit-cover rounded border img-fluid'>
                        <svg viewBox='0 0 24 24' width='24' height='24' stroke='currentColor' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round' class='css-i6dzq1'><circle cx='12' cy='12' r='10'></circle><polygon points='10 8 16 12 10 16 10 8'></polygon></svg>
                    </div>"; 

            $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';
            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteReel d-flex align-items-center" rel=' . $reel->id . ' data-tooltip="Delete Reel">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $rejectReport . $delete . ' </span>';

            return [
                $thumbnail,
                $item->reason,
                $item->desc,
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }
    
    public function deleteReelReport(Request $request)
    {
        $reports = Report::where('id', $request->report_id)->first();
        $deleteReelReports = Report::where('reel_id', $reports->reel_id)->get();

        if (!$deleteReelReports) {
            return response()->json([
                'status' => false,
                'message' => 'Report Not Found',
            ]);
        }
        $deleteReelReports->each->delete();

        return response()->json([
            'status' => true,
            'message' => 'Report Reject Successfully.',
        ]);
    }

    public function deleteReelFromReport(Request $request)
    {
        $reel = Reel::where('id', $request->reel_id)->first();

        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Not Found',
            ]);
        }

        $comments = $reel->comments ?? collect();

        foreach ($comments as $comment) {
            SavedNotification::where('reel_comment_id', $comment->id)
                ->whereIn('type', [
                    Constants::notificationTypeReelLike,
                    Constants::notificationTypeAddReelComment
                ])
                ->delete();
            $comment->delete();
        }

        Like::where('reel_id', $request->reel_id)->delete();

        Report::where('reel_id', $request->reel_id)->delete();

        GlobalFunction::deleteFile($reel->thumbnail);
        GlobalFunction::deleteFile($reel->content);

        SavedNotification::where('reel_id', $request->reel_id)
            ->whereIn('type', [
                Constants::notificationTypeReelLike,
                Constants::notificationTypeAddReelComment
            ])
            ->delete();

        $reel->delete();
 
        return response()->json([
            'status' => true,
            'message' => 'Reel Deleted Successfully.',
            'data' => $reel,
        ]);
    }

    public function fetchCommentsInReelModal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reel_id' => 'required|integer|exists:reels,id',
            'start' => 'required|integer',
            'limit' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $comments = ReelComment::where('reel_id', $request->reel_id)
            ->with(['user', 'company'])
            ->orderByDesc('id')
            ->skip($request->start)
            ->take($request->limit)
            ->get();

        $comments->each(function ($comment) {
            $comment->admin_actor = $this->adminCommentActorPayload($comment);
        });

        $totalComments = ReelComment::where('reel_id', $request->reel_id)->count();
        $hasMoreComments = $totalComments > ($request->start + $request->limit);

        return response()->json([
            'status' => true,
            'message' => 'Fetch Comments.',
            'data' => $comments,
            'hasMore' => $hasMoreComments,
        ]);
    }

    public function deleteReelCommentFromAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'comment_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $comment = ReelComment::where('id', $request->comment_id)->where('user_id', $request->user_id)->first();
        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found'
            ]);
        }
        $commentCount = Reel::where('id', $comment->reel_id)->first();
        $commentCount->comments_count = max(0, $commentCount->comments_count - 1);
        $commentCount->save();

        SavedNotification::where('user_id', $request->user_id)
            ->where('comment_id', $comment->id)
            ->where('type', Constants::notificationTypeComment)
            ->delete();

        $comment->delete();


        return response()->json([
            'status' => true,
            'message' => 'Delete comment successfully',
            'data' => $comment
        ]);
    }

    public function deleteReelByAdmin(Request $request)
    {
        $reel = Reel::find($request->reel_id);
        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Not Found.'
            ]);
        }

        $comments = $reel->comments ?? collect();

        foreach ($comments as $comment) {
            SavedNotification::where('reel_comment_id', $comment->id)
                ->whereIn('type', [
                    Constants::notificationTypeReelLike,
                    Constants::notificationTypeAddReelComment
                ])
                ->delete();
            $comment->delete();
        }

        Like::where('reel_id', $request->reel_id)->delete();

        Report::where('reel_id', $request->reel_id)->delete();

        GlobalFunction::deleteFile($reel->thumbnail);
        GlobalFunction::deleteFile($reel->content);

        SavedNotification::where('reel_id', $request->reel_id)
            ->whereIn('type', [
                Constants::notificationTypeReelLike,
                Constants::notificationTypeAddReelComment
            ])
            ->delete();

        $reel->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reel Deleted Successfully.',
            'data' => $reel
        ]);
    }

    public function musics()
    {
        $categories = MusicCategory::where('is_deleted', Constants::DeletedNo)->orderByDesc('id')->get();
        return view('musics', [
            'categories' => $categories
        ]);
    }

    public function categoryList(Request $request)
    {
        $query = MusicCategory::where('is_deleted', Constants::DeletedNo);
        $totalData = $query->count();

        $columns = 'id';
        $orderDir = 'desc';
        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $columns;
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where('title', 'LIKE', "%{$searchValue}%");
        }

        $totalFiltered = $query->count();

        $result = $query->orderBy($orderColumn, $orderDir)
                        ->offset($start)
                        ->limit($limit)
                        ->get();

        $data = $result->map(function ($item) {

            $edit = '<a href="#" class="ms-3 btn btn-success px-4 text-white edit" rel="'.$item->id.'" data-title="'.$item->title.'" data-tooltip="Edit">'
            . '<svg data-name="Layer 1" height="200" id="Layer_1" viewBox="0 0 200 200" width="200" xmlns="http://www.w3.org/2000/svg">'
            . '<title></title><path d="M170,70.5a10,10,0,0,0-10,10V140a20.06,20.06,0,0,1-20,20H60a20.06,20.06,0,0,1-20-20V60A20.06,20.06,0,0,1,60,40h59.5a10,10,0,0,0,0-20H60A40.12,40.12,0,0,0,20,60v80a40.12,40.12,0,0,0,40,40h80a40.12,40.12,0,0,0,40-40V80.5A10,10,0,0,0,170,70.5Zm-77,39a9.67,9.67,0,0,0,14,0L164.5,52a9.9,9.9,0,0,0-14-14L93,95.5A9.67,9.67,0,0,0,93,109.5Z" fill="#fff"></path></svg>'
            . '</a>';
        
            $delete = '<a href="#" class="ms-3 btn btn-danger px-4 text-white delete" rel=' . $item->id . ' data-tooltip="Delete" ><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';
            $action = '<span class="float-right">' . $edit . $delete . ' </span>';

            return [
                $item->title,
                $item->musics->count(),
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    public function addCategory(Request $request)
    {
        $category = new MusicCategory();
        $category->title = $request->title;
        $category->save();

        return response()->json([
            'status' => true,
            'message' => 'Category Added Successfully',
            'data' => $category,
        ]);
    }

    public function updateCategory(Request $request) 
    {
        $category = MusicCategory::where('id', $request->category_id)->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category Not Found.',
            ]);
        }

        $category->title = $request->title;
        $category->save();

        return response()->json([
            'status' => true,
            'message' => 'Category Updated Successfully',
        ]);
    }

    public function deleteCategory(Request $request) 
    {
        $category = MusicCategory::find($request->category_id);

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category Not Found.',
            ]);
        }

        $category->is_deleted = Constants::Deleted;
        $category->save();

        return response()->json([
            'status' => true,
            'message' => 'Category Deleted Successfully.',
        ]);
    }

    public function musicList(Request $request)
    {
        $query = Music::where('is_deleted', Constants::DeletedNo);
        $totalData = $query->count();

        $columns = 'id';
        $orderDir = 'desc';
        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $columns;
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where('title', 'LIKE', "%{$searchValue}%");
        }

        $totalFiltered = $query->count();

        $result = $query->orderBy($orderColumn, $orderDir)
                        ->offset($start)
                        ->limit($limit)
                        ->get();

        $data = $result->map(function ($item) {

            if ($item->image == null) {
                $image = '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->image . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            $music = '<div class="d-flex align-items-center">'. $image . '<audio id="show_music" class="ms-3" src='. $item->sound .' controls="" ></audio></div>';

            $edit = '<a href="#" class="ms-3 btn btn-success px-4 text-white edit" 
                rel="'.$item->id.'" 
                data-title="'.$item->title.'" 
                data-category_id="'.$item->category_id.'" 
                data-sound="'.$item->sound.'" 
                data-duration="'.$item->duration.'" 
                data-artist="'.$item->artist.'" 
                data-tooltip="Edit">'
            . '<svg data-name="Layer 1" height="200" id="Layer_1" viewBox="0 0 200 200" width="200" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M170,70.5a10,10,0,0,0-10,10V140a20.06,20.06,0,0,1-20,20H60a20.06,20.06,0,0,1-20-20V60A20.06,20.06,0,0,1,60,40h59.5a10,10,0,0,0,0-20H60A40.12,40.12,0,0,0,20,60v80a40.12,40.12,0,0,0,40,40h80a40.12,40.12,0,0,0,40-40V80.5A10,10,0,0,0,170,70.5Zm-77,39a9.67,9.67,0,0,0,14,0L164.5,52a9.9,9.9,0,0,0-14-14L93,95.5A9.67,9.67,0,0,0,93,109.5Z" fill="#fff"></path></svg>'
            . '</a>';
        
            $delete = '<a href="#" class="ms-3 btn btn-danger px-4 text-white delete" rel=' . $item->id . ' data-tooltip="Delete" ><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

            $action = '<span class="float-right">' . $edit . $delete . ' </span>';

            return [
                $music,
                $item->title,
                $item->category->title,
                $item->duration,
                $item->artist,
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    public function addMusic(Request $request)
    {
        $music = new Music();
        $music->title = $request->title;
        if ($request->hasFile("image")) {
            $music->image = GlobalFunction::saveFileAndGivePath($request->file("image"));
        }
        $music->category_id = $request->category_id;
        if ($request->hasFile("sound")) {
            $music->sound = GlobalFunction::saveFileAndGivePath($request->file("sound"));
        }
        $music->duration = $request->duration;
        $music->artist = $request->artist;
        $music->save();

        return response()->json([
            'status' => true,
            'message' => 'Music Added Successfully',
            'data' => $music,
        ]);
    }

    public function updateMusic(Request $request) 
    {
        $music = Music::find($request->music_id);

        if (!$music) {
            return response()->json([
                'status' => false,
                'message' => 'Music Not Found.',
            ]);
        }

        if ($request->has('title')) {
            $music->title = $request->title;
        }
        if ($request->hasFile("image")) {
            GlobalFunction::deleteFile($music->image);
            $music->image = GlobalFunction::saveFileAndGivePath($request->file("image"));
        }
        if ($request->has('category_id')) {
            $music->category_id = $request->category_id;
        }
        if ($request->hasFile("sound")) {
            GlobalFunction::deleteFile($music->sound);
            $music->sound = GlobalFunction::saveFileAndGivePath($request->file("sound"));
        }
        if ($request->has('duration')) {
            $music->duration = $request->duration;
        }
        if ($request->has('artist')) {
            $music->artist = $request->artist;
        }
        $music->save();

        return response()->json([
            'status' => true,
            'message' => 'Music Updated Successfully',
        ]);
    }

    public function deleteMusic(Request $request) 
    {
        $music = Music::find($request->music_id);

        if (!$music) {
            return response()->json([
                'status' => false,
                'message' => 'Music Not Found.',
            ]);
        }

        // GlobalFunction::deleteFile($music->image);
        // GlobalFunction::deleteFile($music->sound);

        $music->is_deleted = Constants::Deleted;
        $music->save();

        return response()->json([
            'status' => true,
            'message' => 'Music Deleted Successfully.',
        ]);
    }
    
}
