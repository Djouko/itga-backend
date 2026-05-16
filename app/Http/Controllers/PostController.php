<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Company;
use App\Models\Constants;
use App\Models\FollowingList;
use App\Models\GlobalFunction;
use App\Models\Interest;
use App\Models\Like;
use App\Models\LikeComment;
use App\Models\Post;
use App\Models\PostContent;
use App\Models\Report;
use App\Models\Room;
use App\Models\RoomUser;
use App\Models\SavedNotification;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\RoomSuggestionService;
use App\Helpers\InputSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
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

    private function csvHasId($csv, int $id): bool
    {
        return in_array((string) $id, array_filter(explode(',', (string) $csv)), true);
    }

    private function csvAppendId($csv, int $id): string
    {
        $ids = array_values(array_filter(explode(',', (string) $csv)));
        if (!in_array((string) $id, $ids, true)) {
            $ids[] = (string) $id;
        }

        return implode(',', $ids) . ',';
    }

    private function companyStoryActorPayload(Company $company, $stories): array
    {
        return [
            'id' => (int) $company->id,
            'identity' => $company->email,
            'username' => 'company-' . $company->id,
            'email' => $company->email,
            'full_name' => $company->name,
            'bio' => $company->description,
            'profile' => $company->logo,
            'background_image' => null,
            'interest_ids' => null,
            'block_user_ids' => null,
            'saved_post_ids' => null,
            'saved_reel_ids' => null,
            'saved_music_ids' => null,
            'followers' => 0,
            'following' => 0,
            'is_verified' => (int) $company->is_verified === 1 ? 2 : 0,
            'is_block' => (int) $company->is_suspended,
            'is_push_notifications' => 1,
            'is_invited_to_room' => 0,
            'is_moderator' => 0,
            'device_token' => $company->device_token,
            'device_type' => null,
            'headline' => $company->sector,
            'about' => $company->description,
            'experience' => null,
            'education' => null,
            'skills' => null,
            'location' => trim(($company->city ?? '') . ' ' . ($company->country ?? '')) ?: null,
            'website' => $company->website,
            'pronouns' => null,
            'profile_type' => 'company',
            'owned_company' => $company,
            'stories' => collect($stories)->values(),
            'created_at' => $company->created_at,
            'updated_at' => $company->updated_at,
        ];
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
        return $item->company ? ($item->company->logo ?? 'null') : ($item->user->profile ?? 'null');
    }

    private function adminActorUserId($item): int
    {
        return (int) ($item->company->owner_user_id ?? $item->user_id ?? $item->user->id ?? 0);
    }

    private function adminActorLink($item): string
    {
        $userId = $this->adminActorUserId($item);
        $username = e($this->adminActorUsername($item));
        $href = $userId > 0 ? './usersDetail/' . $userId : '#';
        $badge = $item->company
            ? '<div class="mt-1"><span class="badge bg-info">Entreprise</span><small class="d-block text-muted">' . e($item->company->name) . '</small></div>'
            : '';

        return '<a href="' . $href . '"> ' . $username . ' </a>' . $badge;
    }

    private function adminActorFullName($item): string
    {
        if (!$item->company) {
            return e($item->user->full_name ?? '');
        }

        $ownerName = $item->user->full_name ?? 'Owner inconnu';
        return e($item->company->name) . '<div class="text-muted small">Owner: ' . e($ownerName) . '</div>';
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

    private function getSuggestedRooms(User $user, $companyActor = null)
    {
        return app(RoomSuggestionService::class)->forViewer($user, $companyActor, 2);
    }

    public function testfileupload(Request $request){
        $result = null;
        if ($request->hasFile('content')) {
            $file = $request->file('content');
            $result = GlobalFunction::saveFileAndGivePath($file);
        }
        // dd($request);
        return response()->json([
            'status'  => true,
            'message' => $request,
            'data'    => $result,
        ]);
    }

    public function addPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id'       => 'required|exists:users,id',
            'company_id'    => 'nullable|integer|exists:companies,id',
            'desc'          => 'nullable|string',
            'tags'          => 'nullable|string',
            'link_preview_json' => 'nullable|string',
            'interest_ids'  => 'nullable',
            'content'       => 'nullable|array',
            'content.*'     => 'file',
            'content_type'  => 'required_if:content,!null',
            'thumbnail'     => 'nullable|array',
            'thumbnail.*'   => 'file|mimes:jpeg,png',
            'audio_waves'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $user = User::find($request->user_id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $post = new Post();
        $post->user_id = (int) $request->user_id;
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }
        if ($companyActor) {
            $post->company_id = $companyActor->id;
        }
        $post->desc = InputSanitizer::sanitizeText($request->desc);
        $post->tags = InputSanitizer::sanitizeText($request->tags, 1000);
        $post->link_preview_json = InputSanitizer::sanitizeJson($request->link_preview_json);
        $post->interest_ids = InputSanitizer::sanitizeIdList($request->interest_ids);
        $post->save();

        // Handle file uploads if present
        if ($request->hasFile('content')) {
            foreach ($request->file('content') as $index => $file) {
                $postContent = new PostContent();
                $postContent->post_id = $post->id;
                $postContent->content = GlobalFunction::saveFileAndGivePath($file);
                $postContent->content_type = $request->content_type;

                if ($request->hasFile("thumbnail.{$index}")) {
                    $postContent->thumbnail = GlobalFunction::saveFileAndGivePath($request->file("thumbnail.{$index}"));
                }

                if ($request->filled('audio_waves')) {
                    $postContent->audio_waves = $request->audio_waves;
                }

                $postContent->save();
            }
        }

        // Send notifications to mentioned users (batch loaded for performance)
        if ($request->filled('mentioned_user_ids')) {
            $rawIds = array_filter(array_map(function ($id) use ($user) {
                $id = (int) trim($id);
                return ($id > 0 && $id != $user->id) ? $id : null;
            }, explode(',', $request->mentioned_user_ids)));

            if (!empty($rawIds)) {
                $mentionedUsers = User::whereIn('id', $rawIds)->get();
                foreach ($mentionedUsers as $mentionedUser) {
                    if ($mentionedUser->is_push_notifications == Constants::pushNotification) {
                        $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' mentioned you in a post.';
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $mentionedUser->device_token, $mentionedUser->device_type, [
                            'type' => Constants::notificationTypeMentionInPost,
                            'post_id' => $post->id,
                        ]);
                    }
                    $savedNotification = new SavedNotification();
                    $savedNotification->my_user_id = (int) $mentionedUser->id;
                    $savedNotification->user_id = (int) $user->id;
                    if ($companyActor) {
                        $savedNotification->company_id = (int) $companyActor->id;
                    }
                    $savedNotification->post_id = (int) $post->id;
                    $savedNotification->type = Constants::notificationTypeMentionInPost;
                    $savedNotification->save();
                }
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Post Uploaded',
            'data'    => $post->load(['content', 'user', 'company']),
        ]);
    }

    public function editPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'post_id' => 'required|exists:posts,id',
            'desc'    => 'nullable|string|max:5000',
            'description' => 'nullable|string|max:5000',
            'tags'    => 'nullable|string',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::find($request->user_id);
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $post = Post::where('id', $request->post_id)
            ->where('user_id', $request->user_id)
            ->when($companyActor, function ($query) use ($companyActor) {
                $query->where('company_id', $companyActor->id);
            }, function ($query) {
                $query->whereNull('company_id');
            })
            ->first();

        if (!$post) {
            return response()->json(['status' => false, 'message' => 'Post not found or unauthorized']);
        }

        // Reposts cannot be edited directly — only the text overlay
        $post->desc = InputSanitizer::sanitizeText($request->desc ?? $request->description);
        if ($request->filled('tags')) {
            $post->tags = InputSanitizer::sanitizeText($request->tags, 1000);
        }
        $post->save();

        $post->load(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company']);

        return response()->json([
            'status'  => true,
            'message' => 'Post updated successfully',
            'data'    => $post,
        ]);
    }

    public function repostPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'post_id' => 'required|exists:posts,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            return response()->json(['status' => false, 'message' => $messages[0]]);
        }

        try {
            $user = User::where('id', $request->user_id)->where('is_block', 0)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'User Not Found']);
            }

            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $originalPost = Post::with(['content', 'user', 'company'])->find($request->post_id);
            if (!$originalPost) {
                return response()->json(['status' => false, 'message' => 'Post Not Found']);
            }

            // If the selected post is itself a repost, all actor guards target the root original.
            $rootPostId = $originalPost->original_post_id ?? $originalPost->id;
            $rootPost = (int) $rootPostId === (int) $originalPost->id
                ? $originalPost
                : Post::with(['content', 'user', 'company'])->find($rootPostId);
            if (!$rootPost) {
                return response()->json(['status' => false, 'message' => 'Post Not Found']);
            }

            $sameActorPost = $companyActor
                ? ((int) $rootPost->company_id === (int) $companyActor->id)
                : ((int) $rootPost->user_id === (int) $user->id && $rootPost->company_id === null);

            // Prevent reposting the same actor's own post.
            if ($sameActorPost) {
                return response()->json(['status' => false, 'message' => 'You cannot repost your own post']);
            }

            // Prevent duplicate reposts per actor (owner user and company are separate actors).
            $existingRepost = Post::where('user_id', $user->id)
                ->where('original_post_id', $rootPostId)
                ->where('company_id', $companyActor ? $companyActor->id : null)
                ->first();
            if ($existingRepost) {
                return response()->json(['status' => false, 'message' => 'You have already reposted this post']);
            }

            $repost = new Post();
            $repost->user_id = $user->id;
            if ($companyActor) {
                $repost->company_id = $companyActor->id;
            }
            $repost->original_post_id = $rootPostId;
            $repost->desc = $request->desc;
            $repost->save();

            // Increment repost count on the root original post
            Post::where('id', $rootPostId)->increment('repost_count');

            // Save notification + push notification
            if ($rootPost && !$sameActorPost) {
                $postOwner = $rootPost->user;

                $savedNotification = new SavedNotification();
                $savedNotification->my_user_id = (int) $postOwner->id;
                $savedNotification->user_id = (int) $user->id;
                if ($companyActor) {
                    $savedNotification->company_id = (int) $companyActor->id;
                }
                $savedNotification->type = Constants::notificationTypeRepost;
                $savedNotification->post_id = (int) $repost->id;
                $savedNotification->save();

                if ($postOwner && $postOwner->is_push_notifications == Constants::pushNotification) {
                    $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' reposted your post.';
                    GlobalFunction::sendPushNotificationToUser($notificationDesc, $postOwner->device_token, $postOwner->device_type, [
                        'type' => (string) Constants::notificationTypeRepost,
                        'post_id' => (string) $repost->id,
                    ]);
                }
            }

            $repost->load(['user', 'company', 'originalPost.user', 'originalPost.company', 'originalPost.content']);

            return response()->json([
                'status' => true,
                'message' => 'Post Reposted Successfully',
                'data' => $repost,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Repost failed: ' . $e->getMessage(),
            ], 200);
        }
    }

    public function fetchReposts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'post_id' => 'required|exists:posts,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $reposts = Post::where('original_post_id', $request->post_id)
            ->with(['user', 'company'])
            ->orderByDesc('created_at')
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch reposts successfully',
            'data' => $reposts,
        ]);
    }

    public function fetchPosts(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|exists:users,id',
            'limit' => 'required|integer|min:1',
            'should_send_suggested_room' => 'required|boolean',
            'fetch_post_type' => 'required',
            'start' => 'nullable|integer|min:0',
            'excluded_post_ids' => 'nullable|string',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $user = User::where('is_block', 0)->find($request->my_user_id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $blockUserIds = explode(',', $user->block_user_ids);
        $fetchPostsQuery = Post::with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
            ->whereRelation('user', 'is_block', 0)
            ->whereNotIn('user_id', $blockUserIds)
            ->where('is_restricted', 0);

        $excludedPostIds = array_values(array_filter(array_map('intval', explode(',', (string) $request->excluded_post_ids))));
        if (!empty($excludedPostIds)) {
            $fetchPostsQuery->whereNotIn('id', $excludedPostIds);
        }

        if ($request->fetch_post_type == Constants::RandomPosts) {
            $fetchPostsQuery->inRandomOrder();
        } elseif ($request->fetch_post_type == Constants::FollowingPosts) {
            $followingIds = \DB::table('following_lists')
                ->where('my_user_id', $user->id)
                ->pluck('user_id');
            $fetchPostsQuery->whereIn('user_id', $followingIds)->latest();
            if ($request->start) {
                $fetchPostsQuery->offset($request->start);
            }
        } else {
            $fetchPostsQuery->latest();
            if ($request->start) {
                $fetchPostsQuery->offset($request->start);
            }
        }

        $fetchPosts = $fetchPostsQuery->limit($request->limit)->get();
        $fetchPosts = Post::processPosts($fetchPosts, $request->my_user_id, $companyActor ? $companyActor->id : null);

        $suggestedRooms = $request->should_send_suggested_room
            ? $this->getSuggestedRooms($user, $companyActor)
            : [];

        return response()->json([
            'status' => true,
            'message' => 'Fetch posts successfully',
            'data' => $fetchPosts,
            'suggestedRooms' => $suggestedRooms,
        ]);
    }

    public function addComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'post_id' => 'required',
            'desc' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $post = Post::where('id', $request->post_id)->with(['user', 'content'])->first();
        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post Not Found',
            ]);
        }

        $comment = new Comment();
        $comment->user_id = (int) $request->user_id;
        if ($companyActor) {
            $comment->company_id = $companyActor->id;
        }
        $comment->post_id = (int) $request->post_id;
        $comment->desc = InputSanitizer::sanitizeText($request->desc, 2000);
        if ($request->filled('parent_id')) {
            $comment->parent_id = (int) $request->parent_id;
        }
        $comment->save();

        $post->comments_count += 1;
        $post->save();

        $toUser = $post->user;

        if ($toUser->id != $request->user_id) {
            if ($toUser->is_push_notifications == Constants::pushNotification) {
                $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' has commented: ' . $request->desc;
                GlobalFunction::sendPushNotificationToUser($notificationDesc, $toUser->device_token, $toUser->device_type, [
                    'type' => Constants::notificationTypeComment,
                    'post_id' => $request->post_id,
                ]);
            }
        }

        $comment->post = $post;
        $comment->load(['user', 'company']);

        if ($user->id != $post->user_id) {
            $type = Constants::notificationTypeComment;

            $savedNotification = new SavedNotification();
            $savedNotification->my_user_id = (int) $post->user->id;
            $savedNotification->user_id = (int) $request->user_id;
            if ($companyActor) {
                $savedNotification->company_id = (int) $companyActor->id;
            }
            $savedNotification->post_id = (int) $request->post_id;
            $savedNotification->comment_id = (int) $comment->id;
            $savedNotification->type = $type;
            $savedNotification->save();
        }

        // Notify the parent comment author when replying
        if ($request->filled('parent_id')) {
            $parentComment = Comment::find($request->parent_id);
            if ($parentComment && $parentComment->user_id != $user->id) {
                $parentAuthor = User::find($parentComment->user_id);
                if ($parentAuthor && $parentAuthor->is_push_notifications == Constants::pushNotification) {
                    $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' replied to your comment.';
                    GlobalFunction::sendPushNotificationToUser($notificationDesc, $parentAuthor->device_token, $parentAuthor->device_type, [
                        'type' => Constants::notificationTypeComment,
                        'post_id' => $request->post_id,
                    ]);
                }
                $replyNotification = new SavedNotification();
                $replyNotification->my_user_id = (int) $parentComment->user_id;
                $replyNotification->user_id = (int) $user->id;
                if ($companyActor) {
                    $replyNotification->company_id = (int) $companyActor->id;
                }
                $replyNotification->post_id = (int) $request->post_id;
                $replyNotification->comment_id = (int) $comment->id;
                $replyNotification->type = Constants::notificationTypeComment;
                $replyNotification->save();
            }
        }

        // Send notifications to mentioned users in comment (batch loaded)
        if ($request->filled('mentioned_user_ids')) {
            $rawIds = array_filter(array_map(function ($id) use ($user) {
                $id = (int) trim($id);
                return ($id > 0 && $id != $user->id) ? $id : null;
            }, explode(',', $request->mentioned_user_ids)));

            if (!empty($rawIds)) {
                $mentionedUsers = User::whereIn('id', $rawIds)->get();
                foreach ($mentionedUsers as $mentionedUser) {
                    if ($mentionedUser->is_push_notifications == Constants::pushNotification) {
                        $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' mentioned you in a comment.';
                        GlobalFunction::sendPushNotificationToUser($notificationDesc, $mentionedUser->device_token, $mentionedUser->device_type, [
                            'type' => Constants::notificationTypeMentionInComment,
                            'post_id' => $post->id,
                        ]);
                    }
                    $savedNotification = new SavedNotification();
                    $savedNotification->my_user_id = (int) $mentionedUser->id;
                    $savedNotification->user_id = (int) $user->id;
                    if ($companyActor) {
                        $savedNotification->company_id = (int) $companyActor->id;
                    }
                    $savedNotification->post_id = (int) $post->id;
                    $savedNotification->comment_id = (int) $comment->id;
                    $savedNotification->type = Constants::notificationTypeMentionInComment;
                    $savedNotification->save();
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Comment Placed',
            'data' => $comment
        ]);
    }

    public function fetchComments(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'post_id' => 'required',
            'my_user_id' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $companyActor = null;
        if ($request->filled('company_id')) {
            $viewer = User::where('id', $request->my_user_id)->where('is_block', 0)->first();
            if (!$viewer) {
                return response()->json(['status' => false, 'message' => 'User Not Found']);
            }

            $companyActor = $this->resolveCompanyActor($request, $viewer);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }
        }

        $fetchComments = Comment::where('post_id', $request->post_id)
            ->whereNull('parent_id')
            ->with(['user' => function ($query) {
                $query->where('is_block', 0);
            }, 'company', 'likes' => function ($query) use ($request, $companyActor) {
                $query->where('user_id', $request->my_user_id)
                    ->where('company_id', $companyActor ? $companyActor->id : null);
            }])
            ->withCount('likes as comment_like_count')
            ->withCount('replies as reply_count')
            ->orderBy('id', 'DESC')
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        foreach ($fetchComments as $fetchComment) {
            $fetchComment->is_like = $fetchComment->likes->isNotEmpty() ? 1 : 0;
        }

        return response()->json([
            'status' => true,
            'message' => 'Fetch Comments',
            'data' => $fetchComments,
        ]);
    }

    public function fetchReplies(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'comment_id' => 'required',
            'my_user_id' => 'required',
            'start' => 'required',
            'limit' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $companyActor = null;
        if ($request->filled('company_id')) {
            $viewer = User::where('id', $request->my_user_id)->where('is_block', 0)->first();
            if (!$viewer) {
                return response()->json(['status' => false, 'message' => 'User Not Found']);
            }

            $companyActor = $this->resolveCompanyActor($request, $viewer);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }
        }

        $replies = Comment::where('parent_id', $request->comment_id)
            ->with(['user' => function ($query) {
                $query->where('is_block', 0);
            }, 'company', 'likes' => function ($query) use ($request, $companyActor) {
                $query->where('user_id', $request->my_user_id)
                    ->where('company_id', $companyActor ? $companyActor->id : null);
            }])
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
            'message' => 'Fetch Replies',
            'data' => $replies,
        ]);
    }

    public function editComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'comment_id' => 'required|integer|exists:comments,id',
            'desc' => 'required|string|max:500',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', 0)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $commentQuery = Comment::where('id', $request->comment_id)
            ->where('user_id', $user->id);
        if ($companyActor) {
            $commentQuery->where('company_id', $companyActor->id);
        } else {
            $commentQuery->whereNull('company_id');
        }

        $comment = $commentQuery->first();

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found or you do not have permission to edit this comment.',
            ]);
        }

        $comment->desc = InputSanitizer::sanitizeText($request->desc, 500);
        $comment->is_edited = 1;
        $comment->save();

        $comment->load(['user', 'company']);

        return response()->json([
            'status' => true,
            'message' => 'Comment updated successfully',
            'data' => $comment,
        ]);
    }

    public function deleteComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'comment_id' => 'required|exists:comments,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', 0)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User Not Found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $commentQuery = Comment::where('id', $request->comment_id)->where('user_id', $user->id);
        if ($companyActor) {
            $commentQuery->where('company_id', $companyActor->id);
        } else {
            $commentQuery->whereNull('company_id');
        }

        $comment = $commentQuery->first();

        if ($comment) {

            $replyCount = Comment::where('parent_id', $comment->id)->count();
            Comment::where('parent_id', $comment->id)->delete();

            $post = Post::where('id', $comment->post_id)->first();
            $post->comments_count = max(0, $post->comments_count - 1 - $replyCount);
            $post->save();

            SavedNotification::where('user_id', $user->id)
                ->where('comment_id', $comment->id)
                ->where('type', Constants::notificationTypeComment)
                ->where('company_id', $companyActor ? $companyActor->id : null)
                ->delete();

            $comment->delete();


            return response()->json([
                'status' => true,
                'message' => 'Delete comment successfully',
                'data' => $comment
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Comment not found'
        ]);
    }

    public function likePost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'post_id' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();
        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $post = Post::where('id', $request->post_id)->with(['user', 'content'])->first();
            if ($post) {
                $likeRecord = Like::where('user_id', $request->user_id)
                    ->where('post_id', $request->post_id)
                    ->where('company_id', $companyActor ? $companyActor->id : null)
                    ->first();
                if ($likeRecord) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Already Liked',
                    ]);
                } else {
                    $like = new Like();
                    $like->user_id = (int) $request->user_id;
                    if ($companyActor) {
                        $like->company_id = $companyActor->id;
                    }
                    $like->post_id = (int) $request->post_id;
                    $like->save();

                    $post->likes_count += 1;
                    $post->save();

                    $postUser = $post->user;

                    if ($postUser->id != $request->user_id) {
                        if ($postUser->is_push_notifications == 1) {
                            $notificationDesc = $this->actorDisplayName($user, $companyActor) . ' has liked your post.';
                            GlobalFunction::sendPushNotificationToUser($notificationDesc, $postUser->device_token, $postUser->device_type, [
                                'type' => Constants::notificationTypePostLike,
                                'post_id' => $request->post_id,
                            ]);
                        }
                    }

                    if ($user->id != $post->user_id) {
                        $type = Constants::notificationTypePostLike;

                        $savedNotification = new SavedNotification();
                        $savedNotification->my_user_id = (int) $post->user->id;
                        $savedNotification->user_id = (int) $request->user_id;
                        if ($companyActor) {
                            $savedNotification->company_id = (int) $companyActor->id;
                        }
                        $savedNotification->post_id = (int) $request->post_id;
                        $savedNotification->type = $type;
                        $savedNotification->save();
                    }

                    $like->post = $post;

                    return response()->json([
                        'status' => true,
                        'message' => 'Post Liked',
                        'data' => $like,
                    ]);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Post Not Found',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function dislikePost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'post_id' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();
        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $likedPost = Like::where('user_id', $request->user_id)
                ->where('post_id', $request->post_id)
                ->where('company_id', $companyActor ? $companyActor->id : null)
                ->first();
            if ($likedPost) {
                $likeCount = Post::where('id', $request->post_id)->first();
                $likeCount->likes_count = max(0, $likeCount->likes_count - 1);
                $likeCount->save();

                $likedPost->delete();

                $userNotification = SavedNotification::where('post_id', $request->post_id)
                    ->where('user_id', $request->user_id)
                    ->where('company_id', $companyActor ? $companyActor->id : null)
                    ->where('type', Constants::notificationTypePostLike)
                    ->get();
                $userNotification->each->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Post Dislike',
                    'data' => $likedPost,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Post Already Dislike',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
    }

    public function reportPostList(Request $request)
    {
        $reportType = 1;
        $totalData = Report::where('type', $reportType)->count();
        $rows = Report::where('type', $reportType)->with(['post.user', 'post.company'])->orderBy('id', 'DESC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'post_id',
            2 => 'reason',
            3 => 'desc',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        if (empty($request->input('search.value'))) {
            $result = Report::where('type', $reportType)
                ->with(['post.user', 'post.company'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');
            $buildSearchQuery = function () use ($reportType, $search) {
                return Report::where('type', $reportType)
                    ->where(function ($q) use ($search) {
                        $q->where('reason', 'LIKE', "%{$search}%")
                            ->orWhere('desc', 'LIKE', "%{$search}%")
                            ->orWhereHas('post.user', function ($uq) use ($search) {
                                $uq->where('full_name', 'LIKE', "%{$search}%")
                                    ->orWhere('username', 'LIKE', "%{$search}%");
                            })
                            ->orWhereHas('post.company', function ($cq) use ($search) {
                                $cq->where('name', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%");
                            });
                    });
            };

            $result = $buildSearchQuery()
                ->with(['post.user', 'post.company'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = $buildSearchQuery()->count();
        }
        $data = [];
        foreach ($result as $item) {

            $post = $item->post;

            if (!$post) {
                $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';
                $data[] = [
                    '<span class="text-muted">Post introuvable</span>',
                    $item->reason,
                    $item->desc ?? 'Note: Post has no description',
                    '<span class="float-right d-flex">' . $rejectReport . '</span>'
                ];
                continue;
            }

            $postContent = PostContent::where('post_id', $item->post_id)->get();
            $contentType = $postContent->count() == 0 ? 3 : $postContent->first()->content_type;
            $firstContent = $postContent->pluck('content');

            if ($item->desc == null) {
                $item->desc = 'Note: Post has no description';
            }

            $profile = e($this->adminActorProfile($post));
            $actorUsername = e($this->adminActorUsername($post));
            $actorUserId = $this->adminActorUserId($post);
            $postDesc = e($post->desc ?? '');
            $postId = (int) $post->id;

            if ($contentType == 0) {
                $viewPost = '<button type="button" class="btn btn-primary viewPost commonViewBtn" data-bs-toggle="modal" data-username=' . $actorUsername . ' data-profile=' . $profile  . ' data-image=' . $firstContent . ' data-userid=' . $actorUserId . '  data-desc="' . $postDesc . '" data-postid="' . $postId . '" rel="' . $item->id . '">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-image"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg> View Post</button>';
            } elseif ($contentType == 1) {
                $viewPost = '<button type="button" class="btn btn-primary viewVideoPost commonViewBtn" data-bs-toggle="modal" data-username=' . $actorUsername . ' data-profile=' . $profile  . ' data-userid=' . $actorUserId . ' data-image=' . $firstContent . ' data-desc="' . $postDesc . '" data-postid="' . $postId . '" rel="' . $item->id . '">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg> View Post</button>';
            } elseif ($contentType == 2) {
                $viewPost = '<button type="button" class="btn btn-primary viewAudioPost commonViewBtn" data-bs-toggle="modal" data-username=' . $actorUsername . ' data-profile=' . $profile  . ' data-audio=' . $firstContent . ' data-userid=' . $actorUserId . ' data-desc="' . $postDesc . '" data-postid="' . $postId . '" rel="' . $item->id . '">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg> View Post</button>';
            } else {
                $viewPost = '<button type="button" class="btn btn-primary viewDescPost commonViewBtn" data-bs-toggle="modal" data-username=' . $actorUsername . ' data-profile=' . $profile  . ' data-desc="' . $postDesc . '" data-userid=' . $actorUserId . ' data-postid="' . $postId . '" rel="' . $item->id . '">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-type"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg> View Post</button>';
            }

            $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';
            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deletePost d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Delete Post">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $rejectReport . $delete . ' </span>';

            $data[] = [
                $viewPost,
                $item->reason,
                $item->desc,
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

    public function reportPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'post_id' => 'required|integer',
            'reason' => 'required|string|max:500',
            'desc' => 'required|string|max:2000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)
            ->where('is_block', 0)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $post = Post::where('id', $request->post_id)->first();

        if ($post != null) {
            $report = new Report();
            $report->type = 1;
            $report->post_id = $request->post_id;
            $report->user_id = (int) $request->user_id;
            $report->reason = InputSanitizer::sanitizeText($request->reason, 500);
            $report->desc = InputSanitizer::sanitizeText($request->desc, 2000);
            $report->save();

            return response()->json([
                'status' => true,
                'message' => 'Report Added Successfully',
                'data' => $report,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Post Not Found',
            ]);
        }
    }

    public function deleteMyPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'post_id' => 'required|exists:posts,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', 0)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $postQuery = Post::where('id', $request->post_id)->where('user_id', $user->id);
        if ($companyActor) {
            $postQuery->where('company_id', $companyActor->id);
        } else {
            $postQuery->whereNull('company_id');
        }
        $post = $postQuery->first();

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post Not Found or you do not have permission to delete this post',
            ]);
        }

        $postComments = Comment::where('post_id', $request->post_id)->get();
        foreach ($postComments as $comment) {
            LikeComment::where('comment_id', $comment->id)->delete();
        }
        $postComments->each->delete();

        $postContents = PostContent::where('post_id', $request->post_id)->get();
        foreach ($postContents as $postContent) {
            GlobalFunction::deleteFile($postContent->content);
            GlobalFunction::deleteFile($postContent->thumb);
        }
        $postContents->each->delete();

        Like::where('post_id', $request->post_id)->delete();
        SavedNotification::where('post_id', $request->post_id)->delete();
        Report::where('post_id', $request->post_id)->where('type', 1)->delete();

        // Decrement repost_count on the original post when deleting a repost
        if (!is_null($post->original_post_id)) {
            Post::where('id', $post->original_post_id)
                ->where('repost_count', '>', 0)
                ->decrement('repost_count');
        }

        $post->delete();

        return response()->json([
            'status' => true,
            'message' => 'Post Delete Successfully',
            'data' => $post,
        ]);
    }

    public function createStory(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'type' => 'required',
            'content' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', Constants::unblocked)
            ->where('id', $request->user_id)
            ->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $story = new Story();
        $story->user_id = (int) $request->user_id;
        if ($companyActor) {
            $story->company_id = (int) $companyActor->id;
        }
        $story->duration = (float) $request->duration;
        $story->type = (int) $request->type;
        if ($request->hasFile('content')) {
            $files = $request->file('content');
            $path = GlobalFunction::saveFileAndGivePath($files);
            $story->content = $path;
        }

        if ($request->hasFile('thumbnail')) {
            $file = $request->file('thumbnail');
            $path = GlobalFunction::saveFileAndGivePath($file);
            $story->thumbnail = $path;
        }

        $story->save();
        $story->load(['user', 'company']);

        return response()->json([
            'status' => true,
            'message' => 'Story Added Successfully',
            'data' => $story,
        ]);
    }

    public function viewStory(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'story_id' => 'required|exists:stories,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();
        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $viewStory = Story::with(['user', 'company'])->where('id', $request->story_id)->first();

            if ($viewStory) {
                $viewColumn = $companyActor ? 'view_by_company_ids' : 'view_by_user_ids';
                $viewActorId = $companyActor ? (int) $companyActor->id : (int) $request->user_id;

                if ($this->csvHasId($viewStory->{$viewColumn}, $viewActorId)) {

                    return response()->json([
                        'status' => true,
                        'message' => 'Story Viewed',
                        'data' => $viewStory,
                    ]);
                } else {

                    $viewStory->{$viewColumn} = $this->csvAppendId($viewStory->{$viewColumn}, $viewActorId);
                    $viewStory->save();

                    return response()->json([
                        'status' => true,
                        'message' => 'Story Viewed',
                        'data' => $viewStory,
                    ]);
                }
            }
            return response()->json([
                'status' => false,
                'message' => 'Story not found',
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);
    }

    public function fetchStory(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|exists:users,id',
            'company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $user = User::find((int) $request->my_user_id);
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $followingUsers = FollowingList::where('my_user_id', $request->my_user_id)
            ->when($companyActor, function ($query) use ($companyActor) {
                $query->where('company_id', $companyActor->id);
            }, function ($query) {
                $query->whereNull('company_id');
            })
            ->whereHas('user', function ($query) {
                $query->where('is_block', Constants::unblocked);
            })
            ->with(['user' => function ($query) {
                $query->with(['stories' => function ($storyQuery) {
                    $storyQuery->whereNull('company_id')
                        ->where('created_at', '>=', now()->subDay())
                        ->with(['user', 'company']);
                }]);
            }])
            ->get()
            ->pluck('user')
            ->filter(function ($user) {
                return $user->stories->isNotEmpty();
            })
            ->values();

        $followedCompanyIdsQuery = DB::table('company_followers')
            ->where('user_id', $request->my_user_id);
        $companyActor
            ? $followedCompanyIdsQuery->where('follower_company_id', $companyActor->id)
            : $followedCompanyIdsQuery->whereNull('follower_company_id');

        $followedCompanyIds = $followedCompanyIdsQuery->pluck('company_id');
        $followedCompanyActors = Company::whereIn('id', $followedCompanyIds)
            ->where('is_verified', 1)
            ->where('is_suspended', 0)
            ->with(['stories' => function ($storyQuery) {
                $storyQuery->where('created_at', '>=', now()->subDay())
                    ->with(['user', 'company']);
            }])
            ->get()
            ->filter(function ($company) {
                return $company->stories->isNotEmpty();
            })
            ->map(function ($company) {
                return $this->companyStoryActorPayload($company, $company->stories);
            })
            ->values();

        $storyActors = collect();
        if ($companyActor) {
            $companyActor->load(['stories' => function ($storyQuery) {
                $storyQuery->where('created_at', '>=', now()->subDay())
                    ->with(['user', 'company']);
            }]);
            if ($companyActor->stories->isNotEmpty()) {
                $storyActors->push($this->companyStoryActorPayload($companyActor, $companyActor->stories));
            }
        }

        $followingUsers->each(function ($user) use ($storyActors) {
            $storyActors->push($user);
        });
        $followedCompanyActors->each(function ($companyActorPayload) use ($storyActors) {
            $storyActors->push($companyActorPayload);
        });

        return response()->json([
            'status' => true,
            'message' => 'Story fetched successfully.',
            'data' => $storyActors->values(),
        ]);
    }

    public function uploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'uploadFile' => 'required|file|max:30720|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,mp3,wav,aac,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        if ($request->hasFile('uploadFile')) {
            $file = $request->file('uploadFile');

            $path = GlobalFunction::saveFileAndGivePath($file);

            return response()->json([
                'status' => true,
                'message' => "Uploaded file path",
                'data' => $path,
            ]);
        }

        return response()->json(['status' => false, 'message' => 'No file provided']);
    }

    public function fetchUsersWhoLikedPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'post_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)->where('id', $request->user_id)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $blockUserIds = explode(',', $user->block_user_ids);

        $likes = Like::with(['user', 'company'])
            ->where('post_id', $request->post_id)
            // ->whereHas('user', function ($query) use ($blockUserIds) {
            //     $query->where('is_block', 0)
            //     ->whereNotIn('id', $blockUserIds);
            // })
            ->get();

        return response()->json([
            'status' => true,
            'message' => "Fetch Liked By User",
            'data' => $likes,
        ]);
    }

    public function searchHashtag(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'keyword' => 'nullable|string|max:100',
            'start' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));
        $keyword = InputSanitizer::sanitizeSearch($request->keyword ?? '');
        $start = (int) ($request->start ?? 0);
        $limit = (int) ($request->limit ?? 25);

        $posts = Post::query()
            ->select('tags')
            ->whereRelation('user', 'is_block', 0)
            ->whereNotIn('user_id', $blockUserIds)
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->orderBy('id', 'DESC')
            ->get();

        $tagCounts = [];

        foreach ($posts as $post) {
            $tags = array_map('trim', explode(',', $post->tags));
            foreach ($tags as $tag) {
                if (!empty($tag)) {
                    if (!empty($keyword) && stripos($tag, $keyword) === false) {
                        continue;
                    }

                    if (!array_key_exists($tag, $tagCounts)) {
                        $tagCounts[$tag] = 0;
                    }
                    $tagCounts[$tag]++;
                }
            }
        }

        $formattedTags = [];
        foreach ($tagCounts as $tag => $count) {
            $formattedTags[] = ['tag' => $tag, 'post_count' => $count];
        }

        usort($formattedTags, function ($left, $right) {
            if ($left['post_count'] === $right['post_count']) {
                return strcasecmp($left['tag'], $right['tag']);
            }

            return $right['post_count'] <=> $left['post_count'];
        });

        $formattedTags = array_values(array_slice($formattedTags, $start, $limit));

        return response()->json([
            'status' => true,
            'message' => 'Search Hashtag Successfully',
            'data' => $formattedTags
        ]);
    }

    public function searchPost(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:200',
            'user_id' => 'required|integer|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $keyword = InputSanitizer::sanitizeSearch($request->keyword ?? '') ?? '';
        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

        $fetchPosts = Post::with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
                        ->where('desc', 'like', '%' . $keyword . '%')
                ->offset((int) $request->start)
                ->limit((int) $request->limit)
                        ->whereRelation('user', 'is_block', 0)
                        ->whereNotIn('user_id', $blockUserIds)
                        ->orderByDesc('id')
                        ->get();

        $fetchPosts = Post::processPosts($fetchPosts, $request->user_id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Search Post Successfully',
            'data' => $fetchPosts,
        ]);
    }

    public function likeDislikeComment(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'comment_id' => 'required|exists:comments,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->where('is_block', 0)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $comment = Comment::where('id', $request->comment_id)->first();
        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found'
            ]);
        }

        $fetchLikeComment = LikeComment::where('user_id', $user->id)
            ->where('comment_id', $request->comment_id)
            ->where('company_id', $companyActor ? $companyActor->id : null)
            ->first();

        if ($fetchLikeComment) {

            $fetchLikeComment->delete();

            return response()->json([
                'status' => true,
                'message' => 'Dislike Comment Successfully.',
                'data' => $fetchLikeComment
            ]);
        } else {

            $likeComment = new LikeComment();
            $likeComment->user_id = (int) $user->id;
            if ($companyActor) {
                $likeComment->company_id = (int) $companyActor->id;
            }
            $likeComment->comment_id = (int) $request->comment_id;
            $likeComment->save();

            return response()->json([
                'status' => true,
                'message' => 'Like Comment Successfully',
                'data' => $likeComment
            ]);
        }
    }

    public function fetchSavedPosts(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

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
        $savedPostIds = array_filter(explode(',', $user->saved_post_ids ?? ''));

        if (empty($savedPostIds)) {
            return response()->json([
                'status' => true,
                'message' => 'Fetch Saved Posts Successfully.',
                'data' => [],
            ]);
        }

        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

        $posts = Post::with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
            ->whereIn('id', $savedPostIds)
            ->whereNotIn('user_id', array_merge($blockUserIds, ['']))
            ->whereRelation('user', 'is_block', 0)
            ->orderByDesc('id')
            ->offset($request->start)
            ->limit($request->limit)
            ->get();

        $posts = Post::processPosts($posts, $request->user_id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Fetch Saved Posts Successfully.',
            'data' => $posts,
        ]);
    }

    public function searchPostByInterestId(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'interest_id' => 'required|integer|exists:interests,id',
            'user_id' => 'required|integer|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:200',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $fetchInterest = Interest::find($request->interest_id);
        if (!$fetchInterest) {
            return response()->json([
                'status' => false,
                'message' => 'Interest Not Found.',
            ]);
        }

        $user = User::where('is_block', Constants::unblocked)
                    ->where('id', $request->user_id)
                    ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $keyword = InputSanitizer::sanitizeSearch($request->keyword ?? '') ?? '';
        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

        $posts = Post::with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
                        ->where('desc', 'like', '%' . $keyword . '%')
                        ->whereRelation('user', 'is_block', Constants::unblocked)
                        ->whereNotIn('user_id', $blockUserIds)
                        ->whereRaw('find_in_set(?, interest_ids)', [(int) $request->interest_id])
                        ->offset((int) $request->start)
                        ->limit((int) $request->limit)
                        ->orderByDesc('id')
                        ->get();

        $posts = Post::processPosts($posts, $request->user_id, $companyActor ? $companyActor->id : null);

        return response()->json([
            'status' => true,
            'message' => 'Search Posts By Interest.',
            'data' => $posts,
        ]);
    }


    // Web
    public function deletePostReport(Request $request)
    {
        $reports = Report::where('id', $request->report_id)->first();
        $deletePostReports = Report::where('post_id', $reports->post_id)->get();

        if ($deletePostReports) {
            $deletePostReports->each->delete();

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

    public function deletePost(Request $request)
    {

        $report = Report::where('id', $request->report_id)->first();

        if ($report) {
            $postContents = PostContent::where('post_id', $report->post_id)->get();
            foreach ($postContents as $postContent) {
                GlobalFunction::deleteFile($postContent->content);
                GlobalFunction::deleteFile($postContent->thumb);
            }
            $postContents->each->delete();

            $post = Post::where('id', $report->post_id)->first();
            $post->delete();

            $postComments = Comment::where('post_id', $request->post_id)->get();
            $postComments->each->delete();

            $postLikes = Like::where('post_id', $request->post_id)->get();
            $postLikes->each->delete();

            $deleteReportRecords = Report::where('post_id', $report->post_id)->get();
            $deleteReportRecords->each->delete();

            $userNotification = SavedNotification::where('post_id', $request->post_id)->get();
            $userNotification->each->delete();

            $report->delete();

            return response()->json([
                'status' => true,
                'message' => 'Post Delete Successfully',
                'data' => $postContents,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'Report Not Found',
        ]);
    }

    public function fetchPostsByHashtag(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'tag' => 'required',
            'start' => 'required',
            'limit' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->user_id)
            ->first();

        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

            $hashtag = Post::whereRelation('user', 'is_block', 0)
                ->whereRaw('find_in_set(?, tags)', [$request->tag])
                ->whereNotIn('user_id', $blockUserIds)
                ->with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
                ->orderBy('id', 'DESC')
                ->offset($request->start)
                ->limit($request->limit)
                ->get();

            $hashtag = Post::processPosts($hashtag, $request->user_id, $companyActor ? $companyActor->id : null);

            return response()->json([
                'status' => true,
                'message' => 'Fetch posts by hashtag successfully',
                'data' => $hashtag,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);
    }

    public function fetchPostByPostId(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
            'post_id' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('is_block', 0)
            ->where('id', $request->my_user_id)
            ->first();

        if ($user) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            $blockUserIds = explode(',', $user->block_user_ids);

            $fetchPost = Post::where('id', $request->post_id)->with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])
                ->whereRelation('user', 'is_block', 0)
                ->whereNotIn('user_id', $blockUserIds)
                ->first();

            if ($fetchPost) {
                $isPostLike = Like::where('user_id', $request->my_user_id)
                    ->where('post_id', $fetchPost->id)
                    ->where('company_id', $companyActor ? $companyActor->id : null)
                    ->first();
                $fetchPost->is_like = $isPostLike ? 1 : 0;

                return response()->json([
                    'status' => true,
                    'message' => 'Fetch posts',
                    'data' => $fetchPost,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Posts not Available',
                ]);
            }
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);
    }

    public function viewPosts()
    {
        return view('viewPosts');
    }

    public function allPostsList(Request $request)
    {
        $totalData = Post::count();
        $rows = Post::orderBy('id', 'DESC')->get();

        $result = $rows;

        $columns = [
            0 => 'id',
            1 => 'Content',
            2 => 'Thumbnail',
            3 => 'Views',
            4 => 'likes',
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;
        $searchValue = $request->input('search.value');

        $query = Post::query()->with(['user', 'company']);

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->whereHas('user', function ($uq) use ($searchValue) {
                    $uq->where('full_name', 'LIKE', "%{$searchValue}%")
                        ->orWhere('username', 'LIKE', "%{$searchValue}%");
                })
                    ->orWhereHas('company', function ($cq) use ($searchValue) {
                        $cq->where('name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('email', 'LIKE', "%{$searchValue}%");
                    });
            });
            $totalFiltered = $query->count();
        }

        $result = $query
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        $data = [];

        $fetchInterests = Interest::get();

        foreach ($result as $item) {

            $postContent = PostContent::where('post_id', $item->id)->get();
            $contentType = $postContent->count() == 0 ? 3 : $postContent->first()->content_type;
            $firstContent = $postContent->pluck('content');

            if ($item->desc == null) {
                $item->desc = 'Note: Post has no description';
            }

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

            $profile = e($this->adminActorProfile($item));
            $actorUsername = e($this->adminActorUsername($item));
            $actorUserId = $this->adminActorUserId($item);
            $descAttr = e($item->desc);
            $interestsAttr = e($interest_titles_string);

            if ($contentType == 0) {
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewPost commonViewBtn' 
                                    data-bs-toggle='modal' 
                                    data-username='{$actorUsername}' 
                                    data-profile='{$profile}' 
                                    data-image='{$firstContent}' 
                                    data-desc='{$descAttr}' 
                                    data-userid='{$actorUserId}' 
                                    data-postid='{$item->id}' 
                                    data-interests='{$interestsAttr}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-image'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'></rect><circle cx='8.5' cy='8.5' r='1.5'></circle><polyline points='21 15 16 10 5 21'></polyline></svg> View Post</button>";
            } elseif ($contentType == 1) {
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewVideoPost commonViewBtn' 
                                    data-bs-toggle='modal' 
                                    data-username='{$actorUsername}'
                                    data-profile='{$profile}'
                                    data-userid='{$actorUserId}'
                                    data-image='{$firstContent}' 
                                    data-desc='{$descAttr}' 
                                    data-userid='{$actorUserId}' 
                                    data-postid='{$item->id}' 
                                    data-interests='{$interestsAttr}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-video'><polygon points='23 7 16 12 23 17 23 7'></polygon><rect x='1' y='5' width='15' height='14' rx='2' ry='2'></rect></svg> View Post</button>";
            } elseif ($contentType == 2) {
                $firstContent = $postContent->pluck('content')->first();
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewAudioPost commonViewBtn' 
                                    data-bs-toggle='modal'
                                    data-username='{$actorUsername}'
                                    data-profile='{$profile}'
                                    data-audio='{$firstContent}'
                                    data-desc='{$descAttr}'
                                    data-userid='{$actorUserId}'
                                    data-postid='{$item->id}'
                                    data-interests='{$interestsAttr}'
                                    rel='{$item->id}'>
                <svg viewBox='0 0 24 24' width='24' height='24' stroke='currentColor' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round' class='css-i6dzq1'><path d='M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z'></path><path d='M19 10v2a7 7 0 0 1-14 0v-2'></path><line x1='12' y1='19' x2='12' y2='23'></line><line x1='8' y1='23' x2='16' y2='23'></line></svg> View Post</button>";
            } else {
                $viewPost = "<button type='button'
                                    class='btn btn-primary viewDescPost commonViewBtn'
                                    data-bs-toggle='modal'
                                    data-username='{$actorUsername}'
                                    data-profile='{$profile}'
                                    data-desc='{$descAttr}'
                                    data-userid='{$actorUserId}'
                                    data-postid='{$item->id}'
                                    data-interests='{$interestsAttr}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-type'><polyline points='4 7 4 4 20 4 20 7'></polyline><line x1='9' y1='20' x2='15' y2='20'></line><line x1='12' y1='4' x2='12' y2='20'></line></svg> View Post</button>";
            }

            $userName = $this->adminActorLink($item);

            $restricted = '<label class="switch"><input type="checkbox" name="restricted" rel="' . $item->id . '" value="' . $item->is_restricted . '" id="postRestricted" class="postRestricted"' . ($item->is_restricted == 1 ? ' checked' : '') . '><span class="slider"></span> </label>';

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deletePost d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Delete Post">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $delete . ' </span>';

            $data[] = [
                $viewPost,
                $userName,
                $this->adminActorFullName($item),
                $item->comments_count,
                $item->likes_count,
                $restricted,
                $item->created_at->format('d-m-Y'),
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

    public function deleteStory(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'story_id' => 'required|exists:stories,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::find((int) $request->my_user_id);
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $storyQuery = Story::where('id', $request->story_id)
            ->where('user_id', $request->my_user_id);
        if ($companyActor) {
            $storyQuery->where('company_id', $companyActor->id);
        } else {
            $storyQuery->whereNull('company_id');
        }
        $story = $storyQuery->first();

        if ($story) {

            GlobalFunction::deleteFile($story->content);
            if ($story->type == 1) {
                GlobalFunction::deleteFile($story->thumbnail);
            }
            $story->delete();

            return response()->json([
                'status' => true,
                'message' => 'Story delete successfully',
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Story not found'
        ]);
    }

    function fetchStoryByID(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'story_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $story = Story::with(['user', 'company'])->where('id', $request->story_id)->first();
        if (!$story) {
            return response()->json([
                'status' => false,
                'message' => 'Story not found'
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Fetch Story By ID successfully',
            'data' => $story,
        ]);
    }

    public function updatePostRestrictionStatus(Request $request)
    {
        $post = Post::where('id', $request->id)->first();
        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found',
            ]);
        }

        $post->is_restricted = $request->is_restricted;
        $post->save();

        return response()->json([
            'status' => true,
            'message' => 'Post Status Updated successfully',
        ]);
    }

    // web Admin panel post modal
    public function fetchPostComment(Request $request)
    {
        // Validate incoming data
        $validator = Validator::make($request->all(), [
            'post_id' => 'required|integer',
            'start' => 'required|integer',
            'limit' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $fetchComments = Comment::where('post_id', $request->post_id)
                                ->with(['user', 'company'])
                                ->withCount('likes as comment_like_count')
                                ->orderBy('id', 'DESC')
                                ->offset($request->start)
                                ->limit($request->limit)
                                ->get();

        $fetchComments->each(function ($comment) {
            $comment->admin_actor = $this->adminCommentActorPayload($comment);
        });

        $hasMoreComments = Comment::where('post_id', $request->post_id)->count() > ($request->start + $request->limit);

        return response()->json([
            'status' => true,
            'message' => 'Fetch Comments',
            'data' => $fetchComments,
            'hasMore' => $hasMoreComments,
        ]);
    }

    public function deleteCommentFromAdmin(Request $request)
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

        $comment = Comment::where('id', $request->comment_id)->where('user_id', $request->user_id)->first();
        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found'
            ]);
        }
        $commentCount = Post::where('id', $comment->post_id)->first();
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

    // CronJob start
    public function deleteStoryFromWeb(Request $request)
    {
        $expectedToken = env('STORY_CLEANUP_TOKEN');
        $providedToken = $request->header('X-Story-Cleanup-Token') ?: $request->query('token');

        if (empty($expectedToken) || empty($providedToken) || !hash_equals((string) $expectedToken, (string) $providedToken)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized Access',
            ], 401);
        }

        $stories = Story::where('created_at', '<=', now()->subDay()->toDateTimeString())->get();
        $deletedCount = 0;

        if ($stories->isNotEmpty()) {
            foreach ($stories as $story) {
                GlobalFunction::deleteFile($story->content);
                if ($story->type == 1) {
                    GlobalFunction::deleteFile($story->thumbnail);
                }
                $story->delete();
                $deletedCount++;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Expired stories deleted successfully',
            'deleted_count' => $deletedCount,
        ]);
    }
    // CronJob End

    public function userStoryList(Request $request)
    {
        $twentyFourHoursAgo = Carbon::now()->subDay();

        $totalData = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->where('user_id', $request->user_id)
            ->count();

        $rows = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->where('user_id', $request->user_id)
            ->with(['user', 'company'])
            ->orderBy('id', 'DESC')
            ->get();

        $result = $rows;

        $columns = [
            0 => 'id'
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;

        $storyQuery = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->where('user_id', $request->user_id)
            ->with(['user', 'company']);

        if (!empty($request->input('search.value'))) {
            $search = $request->input('search.value');
            $storyQuery->where(function ($query) use ($search) {
                    $query->whereHas('user', function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                            ->orWhere('username', 'LIKE', "%{$search}%");
                    })
                        ->orWhereHas('company', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                });
            $totalFiltered = $storyQuery->count();
        }

        $result = $storyQuery
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        $data = [];


        foreach ($result as $item) {
            $contentType = $item->type;

            $timeAgo = Carbon::parse($item->created_at)->diffForHumans();
            $timeAgo = $item->company
                ? '<span class="badge bg-info mb-1">Entreprise</span><br>' . $timeAgo
                : $timeAgo;

            $viewStory = ($contentType == 0) ? '<button type="button" class="btn btn-primary viewStory commonViewBtn" data-bs-toggle="modal" data-image="' . $item->content . '" rel="' . $item->id . '">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-image"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg> View Story</button>'
                : '<button type="button" class="btn btn-primary viewStoryVideo commonViewBtn" data-bs-toggle="modal" data-image="' . $item->content . '" rel="' . $item->id . '">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg> View Story</button>';

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteStory d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Story">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $delete . ' </span>';

            $data[] = [
                $viewStory,
                $timeAgo,
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

    public function viewStories()
    {
        return view('viewStories');
    }

    public function deleteStoryFromAdmin(Request $request)
    {
        $story = Story::where('id', $request->story_id)->first();

        if ($story) {

            GlobalFunction::deleteFile($story->content);
            $story->delete();

            return response()->json([
                'status' => true,
                'message' => 'Story delete successfully',
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Story not found'
        ]);
    }

    public function allStoriesList(Request $request)
    {

        $twentyFourHoursAgo = Carbon::now()->subDay();

        $totalData = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->count();

        $rows = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->with(['user', 'company'])
            ->orderBy('id', 'DESC')
            ->get();

        $result = $rows;

        $columns = [
            0 => 'id'
        ];

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        $totalFiltered = $totalData;

        $searchValue = $request->input('search.value');

        $query = Story::where('created_at', '>=', $twentyFourHoursAgo)
            ->where('created_at', '<=', Carbon::now())
            ->with(['user', 'company']);

        if (!empty($searchValue)) {
            $query->where(function ($query) use ($searchValue) {
                $query->whereHas('user', function ($q) use ($searchValue) {
                    $q->where('full_name', 'LIKE', "%{$searchValue}%")
                        ->orWhere('username', 'LIKE', "%{$searchValue}%");
                })
                    ->orWhereHas('company', function ($q) use ($searchValue) {
                        $q->where('name', 'LIKE', "%{$searchValue}%")
                            ->orWhere('email', 'LIKE', "%{$searchValue}%");
                    });
            });
            $totalFiltered = $query->count();
        }

        $result = $query
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        $data = [];


        foreach ($result as $item) {
            $userName = $this->adminActorLink($item);
            $contentType = $item->type;

            $timeAgo = Carbon::parse($item->created_at)->diffForHumans();

            $viewStory = ($contentType == 0) ? '<button type="button" class="btn btn-primary viewStory commonViewBtn" data-bs-toggle="modal" data-image="' . $item->content . '" rel="' . $item->id . '">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-image"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg> View Story</button>'
                : '<button type="button" class="btn btn-primary viewStoryVideo commonViewBtn" data-bs-toggle="modal" data-image="' . $item->content . '" rel="' . $item->id . '">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg> View Story</button>';

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deleteStory d-flex align-items-center" rel="' . $item->id . '" data-tooltip="Delete Story">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $delete . ' </span>';

            $data[] = [
                $viewStory,
                $userName,
                $timeAgo,
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

    public function test(Request $request)
    {
        $roomUser = RoomUser::where('room_id', $request->room_id)
            ->where(function ($query) {
                $query->where('type', 2)
                    ->orWhere('type', 3);
            })->count();
        return response()->json([
            'status' => true,
            'message' => 'Room User',
            'data' => $roomUser,
        ]);
    }


}

