<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Constants;
use App\Models\FollowingList;
use App\Models\GlobalFunction;
use App\Models\Interest;
use App\Models\Like;
use App\Models\ModerationAuditAppeal;
use App\Models\ModerationAuditLog;
use App\Models\Post;
use App\Models\PostContent;
use App\Models\ProfileVerification;
use App\Models\Reel;
use App\Models\ReelComment;
use App\Models\Report;
use App\Models\Room;
use App\Models\RoomUser;
use App\Models\SavedNotification;
use App\Models\Story;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
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

    private function recordModerationAudit(Request $request, string $action, string $targetType, $targetId = null, ?int $targetOwnerUserId = null, array $metadata = []): void
    {
        try {
            ModerationAuditLog::create([
                'moderator_user_id' => (int) $request->user_id,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId ? (int) $targetId : null,
                'target_owner_user_id' => $targetOwnerUserId,
                'status' => 'success',
                'metadata' => array_filter($metadata, fn ($value) => $value !== null),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Moderation audit log failed', [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'moderator_user_id' => $request->user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function applyModerationAppealFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        if ($request->filled('audit_log_id')) {
            $query->where('audit_log_id', (int) $request->audit_log_id);
        }

        if ($request->filled('appellant_user_id')) {
            $query->where('appellant_user_id', (int) $request->appellant_user_id);
        }

        if ($request->filled('moderator_user_id')) {
            $moderatorUserId = (int) $request->moderator_user_id;
            $query->whereHas('auditLog', function ($auditQuery) use ($moderatorUserId) {
                $auditQuery->where('moderator_user_id', $moderatorUserId);
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->from_date)->toDateString());
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->to_date)->toDateString());
        }
    }

    private function normalizeBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function buildModerationAuditExportRows($rows, bool $includeSensitive): array
    {
        return $rows->map(function ($row) use ($includeSensitive) {
            $item = $row->toArray();
            if (!$includeSensitive) {
                $item['ip_address'] = null;
                $item['user_agent'] = null;
            }

            return $item;
        })->values()->all();
    }

    private function generateModerationAuditCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        $headers = ['id', 'moderator_user_id', 'action', 'target_type', 'target_id', 'target_owner_user_id', 'status', 'metadata', 'ip_address', 'user_agent', 'created_at'];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $metadata = $row['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
            }

            fputcsv($handle, [
                $row['id'] ?? null,
                $row['moderator_user_id'] ?? null,
                $row['action'] ?? null,
                $row['target_type'] ?? null,
                $row['target_id'] ?? null,
                $row['target_owner_user_id'] ?? null,
                $row['status'] ?? null,
                $metadata,
                $row['ip_address'] ?? null,
                $row['user_agent'] ?? null,
                $row['created_at'] ?? null,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function applyModerationAuditFilters($query, Request $request): void
    {
        if ($request->filled('action')) {
            $query->where('action', (string) $request->action);
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', (string) $request->target_type);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        if ($request->filled('moderator_user_id')) {
            $query->where('moderator_user_id', (int) $request->moderator_user_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->from_date)->toDateString());
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->to_date)->toDateString());
        }

        if ($request->filled('keyword')) {
            $keyword = InputSanitizer::sanitizeSearch($request->keyword, 120);
            $query->where(function ($searchQuery) use ($keyword) {
                $searchQuery->where('action', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('target_type', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('status', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('ip_address', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('user_agent', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('moderator_user_id', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('target_id', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('target_owner_user_id', 'LIKE', '%' . $keyword . '%');
            });
        }
    }

    private function companyAsUserListItem(Company $company): array
    {
        $company->followers_count = DB::table('company_followers')
            ->where('company_id', $company->id)
            ->count();
        $company->published_offers_count = $company->publishedOffers()->count();
        $company->job_offers_count = $company->jobOffers()->count();

        return [
            'id' => (int) ($company->owner_user_id ?: -1 * $company->id),
            'identity' => 'company:' . $company->id,
            'full_name' => $company->name,
            'username' => 'company-' . $company->id,
            'email' => $company->email,
            'bio' => $company->description,
            'profile' => $company->logo,
            'background_image' => null,
            'interest_ids' => 'company',
            'block_user_ids' => null,
            'saved_post_ids' => null,
            'saved_reel_ids' => null,
            'saved_music_ids' => null,
            'followers' => (int) $company->followers_count,
            'following' => 0,
            'is_verified' => (int) ($company->is_verified ? 2 : 0),
            'is_block' => 0,
            'is_push_notifications' => 1,
            'is_invited_to_room' => 0,
            'is_moderator' => 0,
            'device_token' => null,
            'device_type' => null,
            'headline' => $company->sector,
            'about' => $company->description,
            'experience' => null,
            'education' => null,
            'skills' => null,
            'location' => $company->city ? trim($company->city . ($company->country ? ', ' . $company->country : '')) : null,
            'website' => $company->website,
            'pronouns' => null,
            'profile_type' => 'company',
            'owned_company' => $company,
            'created_at' => optional($company->created_at)->toDateTimeString(),
            'updated_at' => optional($company->updated_at)->toDateTimeString(),
        ];
    }

    private function applyCompanyNotificationScope($query, int $companyId): void
    {
        $query->where(function ($q) use ($companyId) {
            $q->whereHas('post', function ($postQuery) use ($companyId) {
                $postQuery->where('company_id', $companyId);
            })->orWhereHas('reel', function ($reelQuery) use ($companyId) {
                $reelQuery->where('company_id', $companyId);
            })->orWhereHas('room', function ($roomQuery) use ($companyId) {
                $roomQuery->where('company_id', $companyId);
            })->orWhere(function ($followQuery) use ($companyId) {
                $followQuery->where('type', Constants::notificationTypeFollow)
                    ->where('item_id', $companyId);
            });
        });
    }

    private function applyPersonalNotificationScope($query): void
    {
        $query->whereDoesntHave('post', function ($postQuery) {
            $postQuery->whereNotNull('company_id');
        })->whereDoesntHave('reel', function ($reelQuery) {
            $reelQuery->whereNotNull('company_id');
        })->whereDoesntHave('room', function ($roomQuery) {
            $roomQuery->whereNotNull('company_id');
        })->where(function ($itemQuery) {
            $itemQuery->whereNull('item_id')
                ->orWhere('type', '!=', Constants::notificationTypeFollow);
        });
    }

    public function users()
    {
        return view('users');
    }

    public function userListWeb(Request $request)
    {
        $totalData = User::count();
        $rows = User::orderBy('id', 'DESC')->get();

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
            $result = User::offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');
            $result = User::Where('full_name', 'LIKE', "%{$search}%")->orWhere('username', 'LIKE', "%{$search}%")
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = User::Where('full_name', 'LIKE', "%{$search}%")->orWhere('username', 'LIKE', "%{$search}%")->count();
        }
        $data = [];
        foreach ($result as $item) {
             
            if ($item->is_verified == 2 || $item->is_verified == 3) {
                $is_verified = '<img src="asset/image/verified.svg" class="verified_icon_top">';
                $username = $item->full_name . $is_verified;
            } else {
                $username = $item->full_name;
            }

            if ($item->profile == null) {
                $image = '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->profile . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            if ($item->device_type == 0) {
                $device_type = 'Android';
            } else {
                $device_type = 'iOS';
            }

            if ($item->is_block == 0) {
                $blockUser = '<a href="#" class="btn btn-danger px-4 text-white blockUserBtn" rel=' . $item->id . ' data-tooltip="Block User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg> <span class="ms-2"> Block </span>') . '</a>';
            } else {
                $blockUser = '<a href="#" class="btn btn-primary px-4 text-white unblockUserBtn" rel=' . $item->id . ' data-tooltip="Unblock User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg> <span class="ms-2"> Unblock </span>') . '</a>';
            }

            if ($item->is_moderator == 1) {
                $moderator = '<label class="switch"><input type="checkbox" name="moderator" rel="' . $item->id . '" value="' . $item->is_moderator . '" id="moderator" class="moderator" checked ><span class="slider"></span> </label>';
            } else {
                $moderator = '<label class="switch"><input type="checkbox" name="moderator" rel="' . $item->id . '" value="' . $item->is_moderator . '" id="moderator" class="moderator"><span class="slider"></span> </label>';
            }

            $view = '<a href="usersDetail/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> <span class="ms-2"> View </span>') . '</a>';
            $action = '<span class="float-right">' . $blockUser . $view . ' </span>';

            $data[] = [
                $image,
                $username,
                $item->username,
                $device_type,
                $moderator,
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

    public function moderatorsList(Request $request)
    {
        // Columns used for ordering
        $columns = [
            0 => 'id',
            1 => 'image',
        ];

        // Basic input validation and default settings
        $limit = $request->input('length', 10);
        $start = $request->input('start', 0);
        $order = $columns[$request->input('order.0.column', 0)];
        $dir = $request->input('order.0.dir', 'DESC');
        $search = $request->input('search.value');
        $totalData = [];

        // Fetch filtered and paginated data
        $query = User::where('is_moderator', 1);

        // Apply search filter if present
        if (!empty($search)) {
            $query->where(function ($query) use ($search) {
                $query->where('full_name', 'LIKE', "%{$search}%")
                ->orWhere('username', 'LIKE', "%{$search}%");
            });
        }

        // Clone the query to get the total filtered count
        $totalFiltered = $query->count();

        // Apply pagination and ordering
        $result = $query->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        // Preparing the data array
        $data = $result->map(function ($item) {
            $username = $item->full_name;
            if (in_array($item->is_verified, [2, 3])) {
                $username .= '<img src="asset/image/verified.svg" class="verified_icon_top">';
            }

            $image = $item->profile
                ? '<img src="' . $item->profile . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">'
                : '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';

            $device_type = $item->device_type == 0 ? 'Android' : 'iOS';

            $blockUser = $item->is_block == 0
                ? '<a href="#" class="btn btn-danger px-4 text-white blockUserBtn" rel=' . $item->id . ' data-tooltip="Block User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg> <span class="ms-2"> Block </span>') . '</a>'
                : '<a href="#" class="btn btn-primary px-4 text-white unblockUserBtn" rel=' . $item->id . ' data-tooltip="Unblock User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg> <span class="ms-2"> Unblock </span>') . '</a>';

            $moderator = '<label class="switch">
            <input type="checkbox" name="moderator" rel="' . $item->id . '" value="' . $item->is_moderator . '" class="moderator" ' . ($item->is_moderator ? 'checked' : '') . '>
            <span class="slider"></span>
        </label>';

            $view = '<a href="usersDetail/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> <span class="ms-2"> View </span>') . '</a>';
            $action = '<span class="float-right">' . $blockUser . $view . ' </span>';

            return [
                $image,
                $username,
                $item->username,
                $device_type,
                $moderator,
                $action,
            ];
        })->toArray();

        // JSON response
        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalData,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ]);
    }

    public function verifiedUserList(Request $request)
    {
        $totalData = User::where('is_verified', Constants::is_verified)->count();
        $rows = User::where('is_verified', Constants::is_verified)->orderBy('id', 'DESC')->get();

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
        $searchValue = $request->input('search.value');

        $query = User::where('is_verified', Constants::is_verified);

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('full_name', 'LIKE', "%{$searchValue}%")
                ->orWhere('username', 'LIKE', "%{$searchValue}%");
            });
        }

        $result = $query->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        $totalFiltered = $result->count();

        $data = [];
        foreach ($result as $item) {

            if ($item->is_verified == 2 || $item->is_verified == 3) {
                $is_verified = '<img src="asset/image/verified.svg" class="verified_icon_top">';
                $username = $item->full_name . $is_verified;
            } else {
                $username = $item->full_name;
            }
            

            if ($item->profile == null) {
                $image = '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->profile . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            if ($item->device_type == 0) {
                $device_type = 'Android';
            } else {
                $device_type = 'iOS';
            }

            if ($item->is_block == 0) {
                $blockUser = '<a href="#" class="btn btn-danger px-4 text-white blockUserBtn" rel=' . $item->id . ' data-tooltip="Block User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg> <span class="ms-2"> Block </span>') . '</a>';
            } else {
                $blockUser = '<a href="#" class="btn btn-primary px-4 text-white unblockUserBtn" rel=' . $item->id . ' data-tooltip="Unblock User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg> <span class="ms-2"> Unblock </span>') . '</a>';
            }

            $view = '<a href="usersDetail/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> <span class="ms-2"> View </span>') . '</a>';
            $action = '<span class="float-right">' . $blockUser . $view . ' </span>';

            $data[] = [
                $image,
                $username,
                $item->username,
                $device_type,
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

    public function verifiedUserBySubscriptionList(Request $request)
    {
        $totalData = User::where('is_verified', Constants::is_subscribe_verified)->count();
        $rows = User::where('is_verified', Constants::is_subscribe_verified)->orderBy('id', 'DESC')->get();

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
        $searchValue = $request->input('search.value');

        $query = User::where('is_verified', Constants::is_subscribe_verified);

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('full_name', 'LIKE', "%{$searchValue}%")
                ->orWhere('username', 'LIKE', "%{$searchValue}%");
            });
        }

        $result = $query->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir)
            ->get();

        $totalFiltered = $result->count();
        $data = [];
        foreach ($result as $item) {

            $is_verified = '<img src="asset/image/verified.svg" class="verified_icon_top">';
            $username = $item->full_name . $is_verified;

            if ($item->profile == null) {
                $image = '<img src="asset/image/default.png" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            } else {
                $image = '<img src="' . $item->profile . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';
            }

            if ($item->device_type == 0) {
                $device_type = 'Android';
            } else {
                $device_type = 'iOS';
            }

            if ($item->is_block == 0) {
                $blockUser = '<a href="#" class="btn btn-danger px-4 text-white blockUserBtn" rel=' . $item->id . ' data-tooltip="Block User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg> <span class="ms-2"> Block </span>') . '</a>';
            } else {
                $blockUser = '<a href="#" class="btn btn-primary px-4 text-white unblockUserBtn" rel=' . $item->id . ' data-tooltip="Unblock User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg> <span class="ms-2"> Unblock </span>') . '</a>';
            }

            $view = '<a href="usersDetail/' . $item->id . '" data-title="' . $item->title . '" class="ms-3 btn btn-info px-4 text-white edit" rel=' . $item->id . ' data-tooltip="View User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> <span class="ms-2"> View </span>') . '</a>';
            $action = '<span class="float-right">' . $blockUser . $view . ' </span>';

            $data[] = [
                $image,
                $username,
                $item->username,
                $device_type,
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

    public function usersDetail($id)
    {
        $user = User::where('id', $id)->first();
        if ($user) {
            return view('userDetails', [
                'user' => $user,
            ]);
        }
    }

    public function verifyUser(Request $request)
    {
        $user = User::where('id', $request->user_id)->first();
        if ($user) {
            $user->is_verified = 2;
            $user->save();

            $notificationDesc = "Wow! Your profile has been verified.";
            GlobalFunction::sendPushNotificationToUser($notificationDesc, $user->device_token, $user->device_type);

            $checkUserInVerificationList = ProfileVerification::where('user_id', $user->id)->first();
            if ($checkUserInVerificationList != null) {
                GlobalFunction::deleteFile($checkUserInVerificationList->selfie);
                GlobalFunction::deleteFile($checkUserInVerificationList->document);
                $checkUserInVerificationList->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'User Verified Successfully',
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User Not Found',
        ]);
    }

    public function userPostsList(Request $request)
    {
        $totalData = Post::where('user_id', $request->userId)->count();
        $rows = Post::where('user_id', $request->userId)
                    ->orderBy('id', 'DESC')
                    ->get();

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
        if (empty($request->input('search.value'))) {
            $result = Post::where('user_id', $request->userId)->offset($start)->limit($limit)->orderBy($order, $dir)->get();
        } else {
            $search = $request->input('search.value');
            $result = Post::where('user_id', $request->userId)->Where('name', 'LIKE', "%{$search}%")->offset($start)->limit($limit)->orderBy($order, $dir)->get();
            $totalFiltered = Post::where('user_id', $request->userId)->Where('name', 'LIKE', "%{$search}%")->count();
        }
        $data = [];
        $fetchInterests = Interest::get();

        foreach ($result as $item) {

            $postContent = PostContent::where('post_id', $item->id)->get();
            $contentType = $postContent->count() == 0 ? 3 : $postContent->first()->content_type;
            $firstContent = $postContent->pluck('content');

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

            $profile = $item->user->profile ?? "null";
            if ($contentType == 0) {
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewPost commonViewBtn' 
                                    data-bs-toggle='modal' 
                                    data-username='{$item->user->username}' 
                                    data-profile='{$profile}' 
                                    data-image='{$firstContent}' 
                                    data-desc='{$item->desc}' 
                                    data-userid='{$item->user->id}' 
                                    data-postid='{$item->id}' 
                                    data-interests='{$interest_titles_string}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-image'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'></rect><circle cx='8.5' cy='8.5' r='1.5'></circle><polyline points='21 15 16 10 5 21'></polyline></svg> View Post</button>";
            } elseif ($contentType == 1) {
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewVideoPost commonViewBtn' 
                                    data-bs-toggle='modal' 
                                    data-username='{$item->user->username}'
                                    data-profile='{$profile}'
                                    data-userid='{$item->user->id}'
                                    data-image='{$firstContent}' 
                                    data-desc='{$item->desc}' 
                                    data-userid='{$item->user->id}' 
                                    data-postid='{$item->id}' 
                                    data-interests='{$interest_titles_string}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-video'><polygon points='23 7 16 12 23 17 23 7'></polygon><rect x='1' y='5' width='15' height='14' rx='2' ry='2'></rect></svg> View Post</button>";
            } elseif ($contentType == 2) {
                $firstContent = $postContent->pluck('content')->first();
                $viewPost = "<button type='button' 
                                    class='btn btn-primary viewAudioPost commonViewBtn' 
                                    data-bs-toggle='modal'
                                    data-username='{$item->user->username}'
                                    data-profile='{$profile}'
                                    data-audio='{$firstContent}'
                                    data-desc='{$item->desc}'
                                    data-userid='{$item->user->id}'
                                    data-postid='{$item->id}'
                                    data-interests='{$interest_titles_string}'
                                    rel='{$item->id}'>
                <svg viewBox='0 0 24 24' width='24' height='24' stroke='currentColor' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round' class='css-i6dzq1'><path d='M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z'></path><path d='M19 10v2a7 7 0 0 1-14 0v-2'></path><line x1='12' y1='19' x2='12' y2='23'></line><line x1='8' y1='23' x2='16' y2='23'></line></svg> View Post</button>";
            } else {
                $viewPost = "<button type='button'
                                    class='btn btn-primary viewDescPost commonViewBtn'
                                    data-bs-toggle='modal'
                                    data-username='{$item->user->username}'
                                    data-profile='{$profile}'
                                    data-desc='{$item->desc}'
                                    data-userid='{$item->user->id}'
                                    data-postid='{$item->id}'
                                    data-interests='{$interest_titles_string}'
                                    rel='{$item->id}'>
                <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-type'><polyline points='4 7 4 4 20 4 20 7'></polyline><line x1='9' y1='20' x2='15' y2='20'></line><line x1='12' y1='4' x2='12' y2='20'></line></svg> View Post</button>";
            }


            $restricted = '<label class="switch"><input type="checkbox" name="restricted" rel="' . $item->id . '" value="' . $item->is_restricted . '" id="postRestricted" class="postRestricted"' . ($item->is_restricted == 1 ? ' checked' : '') . '><span class="slider"></span> </label>';

            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete deletePost d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Delete Post">' . __('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $delete . ' </span>';

            $data[] = [
                $viewPost,
                $item->comments_count,
                $item->likes_count,
                $item->created_at->format('d-m-Y'),
                $restricted,
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

    public function blockUserByAdmin($id)
    {
        $user = User::where('id', $id)
            ->get()
            ->first();

        if ($user) {
            $user->is_block = 1;
            $user->save();

            return response()->json([ 
                'status' => true,
                'message' => 'User Added in Blocklist',
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
    }

    public function unblockUserByAdmin($id)
    {
        $user = User::where('id', $id)
            ->get()
            ->first();

        if ($user) {
            $user->is_block = 0;
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'User Added in Blocklist',
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
    }

    public function deletePostFromUserPostTable(Request $request)
    {
        $post = Post::where('id', $request->post_id)->first();
        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post Not Found',
            ]);
        }

        $postContents = PostContent::where('post_id', $request->post_id)->get();
        foreach ($postContents as $postContent) {
            GlobalFunction::deleteFile($postContent->content);
            GlobalFunction::deleteFile($postContent->thumb);
        }
        $postContents->each->delete();

        $postComments = Comment::where('post_id', $request->post_id)->get();
        $postComments->each->delete();

        $postLikes = Like::where('post_id', $request->post_id)->get();
        $postLikes->each->delete(); 

        $post->delete();

        return response()->json([
            'status' => true,
            'message' => 'Post Delete Successfully',
        ]);
       
    }

    public function addUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'login_type' => 'required',
            'device_type' => 'required',
            'device_token' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('identity', $request->identity)->first();

        if ($user) {
            $user->device_type = (int) $request->device_type;
            $user->device_token = $request->device_token;
            $user->save();
            return response()->json([
                'status' => false,
                'message' => 'User is already exist',
                'auth_token' => $this->issueUserApiToken($user, $request),
                'data' => $user,
            ]);

        } else {
            $user = new User();
            $user->identity = $request->identity;
            $user->full_name = $request->full_name;
            $user->login_type = (int) $request->login_type;
            $user->device_type = (int) $request->device_type;
            $user->device_token = $request->device_token;
            $user->save();
            $user = User::where('id', $user->id)->first();
            return response()->json([
                'status' => true,
                'message' => 'User Added succesfully',
                'auth_token' => $this->issueUserApiToken($user, $request),
                'data' => $user,
            ]);

        }
    }

    public function editProfile(Request $request)
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

        try {
            if ($request->has('username')) {
                $user->username = InputSanitizer::sanitizeSearch($request->username, 50);
            }
            if ($request->hasFile('profile')) {
                $path = GlobalFunction::deleteFile($user->profile);
                $file = $request->file('profile');
                $path = GlobalFunction::saveFileAndGivePath($file);
                $user->profile = $path;
            }
            if ($request->hasFile('background_image')) {
                GlobalFunction::deleteFile($user->background_image);
                $file = $request->file('background_image');
                $path = GlobalFunction::saveFileAndGivePath($file);
                $user->background_image = $path;
            }
            if ($request->has('bio')) {
                $user->bio = InputSanitizer::sanitizeText($request->bio, 500);
            }
            if ($request->has('full_name')) {
                $user->full_name = InputSanitizer::sanitizeText($request->full_name, 100);
            }
            if ($request->has('interest_ids')) {
                $user->interest_ids = InputSanitizer::sanitizeIdList($request->interest_ids);
            }
            if ($request->has('block_user_ids')) {
                $user->block_user_ids = InputSanitizer::sanitizeIdList($request->block_user_ids);
            }
            if ($request->has('saved_music_ids')) {
                $user->saved_music_ids = InputSanitizer::sanitizeIdList($request->saved_music_ids);
            }
            if ($request->has('saved_reel_ids')) {
                $user->saved_reel_ids = InputSanitizer::sanitizeIdList($request->saved_reel_ids);
            }
            if ($request->has('saved_post_ids')) {
                $user->saved_post_ids = InputSanitizer::sanitizeIdList($request->saved_post_ids);
            }
            if ($request->has('is_push_notifications')) {
                $user->is_push_notifications = (int) $request->is_push_notifications;
            }
            if ($request->has('is_invited_to_room')) {
                $user->is_invited_to_room = (int) $request->is_invited_to_room;
            }
            if ($request->has('is_verified')) {
                $user->is_verified = (int) $request->is_verified;
            }
            if ($request->has('device_token')) {
                $user->device_token = InputSanitizer::sanitizeSearch($request->device_token, 500);
            }
            if ($request->has('headline')) {
                $user->headline = InputSanitizer::sanitizeText($request->headline, 200);
            }
            if ($request->has('about')) {
                $user->about = InputSanitizer::sanitizeText($request->about, 2000);
            }
            if ($request->has('experience')) {
                $user->experience = InputSanitizer::sanitizeJson($request->experience);
            }
            if ($request->has('education')) {
                $user->education = InputSanitizer::sanitizeJson($request->education);
            }
            if ($request->has('skills')) {
                $user->skills = InputSanitizer::sanitizeJson($request->skills);
            }
            if ($request->has('location')) {
                $user->location = InputSanitizer::sanitizeText($request->location, 200);
            }
            if ($request->has('website')) {
                $user->website = InputSanitizer::sanitizeSearch($request->website, 500);
            }
            if ($request->has('pronouns')) {
                $user->pronouns = InputSanitizer::sanitizeText($request->pronouns, 50);
            }
            $user->save();
            $this->syncOwnedCompanyFromUser($user, $request);

            return response()->json([
                'status' => true,
                'message' => 'User Updated Successfully',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Profile update failed: ' . $e->getMessage(),
            ], 200);
        }
    }

    private function syncOwnedCompanyFromUser(User $user, Request $request): void
    {
        $companies = Company::where('owner_user_id', $user->id)->get();
        if ($companies->isEmpty()) {
            return;
        }

        foreach ($companies as $company) {
            if ($request->has('full_name') && $user->full_name) {
                $company->name = InputSanitizer::sanitizeText($user->full_name, 255);
            }
            if ($request->has('bio') && $user->bio) {
                $company->description = InputSanitizer::sanitizeText($user->bio, 5000);
            }
            if ($request->has('about') && $user->about) {
                $company->description = InputSanitizer::sanitizeText($user->about, 5000);
            }
            if ($request->has('headline') && $user->headline) {
                $company->sector = InputSanitizer::sanitizeText($user->headline, 100);
            }
            if ($request->hasFile('profile') && $user->profile) {
                $company->logo = $user->profile;
            }
            if ($request->has('website') && $user->website) {
                $company->website = InputSanitizer::sanitizeText($user->website, 500);
            }
            if ($request->has('location') && $user->location) {
                $parts = array_map('trim', explode(',', $user->location, 2));
                $company->city = InputSanitizer::sanitizeText($parts[0] ?? null, 100);
                if (isset($parts[1]) && $parts[1] !== '') {
                    $company->country = InputSanitizer::sanitizeText($parts[1], 100);
                }
            }
            if ($request->has('device_token') && $user->device_token) {
                $company->device_token = InputSanitizer::sanitizeSearch($user->device_token, 500);
            }

            $company->save();
        }
    }

    public function followUser(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer',
            'user_id' => 'required|integer',
            'company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $fromUser = User::where('id', $request->my_user_id)->first();
        $toUser = User::where('id', $request->user_id)->first();

        if ($fromUser && $toUser) {
            $companyActor = $this->resolveCompanyActor($request, $fromUser);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            if (!$companyActor && $fromUser->id == $toUser->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot follow yourself',
                ]);
            }

            if ($companyActor && (int) $companyActor->owner_user_id === (int) $toUser->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'A company cannot follow its linked owner profile.',
                ]);
            }

            $followingQuery = FollowingList::where('my_user_id', $request->my_user_id)
                ->where('user_id', $request->user_id);
            $companyActor
                ? $followingQuery->where('company_id', $companyActor->id)
                : $followingQuery->whereNull('company_id');
            $followingList = $followingQuery->first();
            if ($followingList) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is Already in following list',
                ]);
            }

            $blockUserIds = array_filter(explode(',', $fromUser->block_user_ids ?? ''));
            if (in_array($request->user_id, $blockUserIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'You blocked this User',
                ]);
            }

            $following = new FollowingList();
            $following->my_user_id = (int) $request->my_user_id;
            $following->company_id = $companyActor ? (int) $companyActor->id : null;
            $following->user_id = (int) $request->user_id;
            $following->save();

            if (!$companyActor) {
                $fromUser->following += 1;
                $fromUser->save();
            }

            $toUser->followers += 1;
            $toUser->save();

            if ($toUser->is_push_notifications == 1) {
                $notificationDesc = $this->actorDisplayName($fromUser, $companyActor) . ' has started following you.';
                GlobalFunction::sendPushNotificationToUser($notificationDesc, $toUser->device_token, $toUser->device_type, [
                    'type' => Constants::notificationTypeFollow,
                ]);
            }

            $following->user = $fromUser;

            $savedNotification = new SavedNotification();
            $savedNotification->my_user_id = (int) $request->user_id;
            $savedNotification->user_id = (int) $request->my_user_id;
            if ($companyActor) {
                $savedNotification->company_id = (int) $companyActor->id;
            }
            $savedNotification->type = Constants::notificationTypeFollow;
            $savedNotification->save();

            return response()->json([
                'status' => true,
                'message' => 'User Added in Following List',
                'data' => $following,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function fetchFollowingList(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer',
            'company_id' => 'nullable|integer',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }
        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }
        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));

        $fetchFollowingList = FollowingList::whereRelation('user', 'is_block', 0)
                                            ->whereNotIn('user_id', $blockUserIds)
                                            ->where('my_user_id', $request->my_user_id)
                                            ->when($companyActor, function ($query) use ($companyActor) {
                                                $query->where('company_id', $companyActor->id);
                                            }, function ($query) {
                                                $query->whereNull('company_id');
                                            })
                                            ->with('user')
                                            ->offset($request->start)
                                            ->limit($request->limit)
                                            ->get()
                                            ->pluck('user');

        return response()->json([
            'status' => true,
            'message' => 'Fetch Following List',
            'data' => $fetchFollowingList,
        ]);
    }

    public function fetchFollowersList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'keyword' => 'nullable|string|max:100',
            'start' => 'nullable|integer',
            'limit' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $keyword = $request->keyword ? InputSanitizer::sanitizeSearch($request->keyword, 100) : null;
        $start = (int) ($request->start ?? 0);
        $limit = min((int) ($request->limit ?? 20), 200);

        $query = FollowingList::where('user_id', $request->user_id)
            ->whereNull('company_id')
            ->with(['followerUser' => function ($query) use ($keyword) {
                $query->where('is_block', 0);
                if ($keyword) {
                    $query->where(function ($q) use ($keyword) {
                        $q->where('full_name', 'like', '%' . $keyword . '%')
                            ->orWhere('username', 'like', '%' . $keyword . '%');
                    });
                }
            }]);

        $candidateLimit = $start + $limit;

        $humanFollowers = $query
            ->orderBy('created_at', 'desc')
            ->limit($candidateLimit)
            ->get()
            ->filter(function ($row) {
                return $row->followerUser !== null;
            })
            ->map(function ($row) {
                $user = $row->followerUser;
                $user->_followed_at = optional($row->created_at)->timestamp ?? 0;
                return $user;
            });

        $companyFollowers = FollowingList::where('user_id', $request->user_id)
            ->whereNotNull('company_id')
            ->with('company')
            ->orderBy('created_at', 'desc')
            ->limit($candidateLimit)
            ->get()
            ->filter(function ($row) use ($keyword) {
                if (!$row->company || (int) $row->company->is_suspended === 1) {
                    return false;
                }
                if (!$keyword) {
                    return true;
                }
                $haystack = strtolower(($row->company->name ?? '') . ' ' . ($row->company->sector ?? ''));
                return strpos($haystack, strtolower($keyword)) !== false;
            })
            ->map(function ($row) {
                $item = $this->companyAsUserListItem($row->company);
                $item['_followed_at'] = optional($row->created_at)->timestamp ?? 0;
                return $item;
            });

        $fetchFollowersList = $humanFollowers
            ->concat($companyFollowers)
            ->sortByDesc(function ($item) {
                return is_array($item) ? ($item['_followed_at'] ?? 0) : ($item->_followed_at ?? 0);
            })
            ->slice($start, $limit)
            ->values()
            ->map(function ($item) {
                if (is_array($item)) {
                    unset($item['_followed_at']);
                    return $item;
                }
                unset($item->_followed_at);
                return $item;
            });

        return response()->json([
            'status' => true,
            'message' => 'Fetch Followers List',
            'data' => $fetchFollowersList,
        ]);
    }

    public function unfollowUser(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer',
            'user_id' => 'required|integer',
            'company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        $user1 = User::where('id', $request->user_id)->first();

        if ($user && $user1) {
            $companyActor = $this->resolveCompanyActor($request, $user);
            if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
                return $companyActor;
            }

            if (!$companyActor && $user->id == $user1->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot unfollow yourself',
                ]);
            }

            $followingQuery = FollowingList::where('my_user_id', $request->my_user_id)
                ->where('user_id', $request->user_id);
            $companyActor
                ? $followingQuery->where('company_id', $companyActor->id)
                : $followingQuery->whereNull('company_id');
            $followingList = $followingQuery->first();
            if ($followingList) {
                if (!$companyActor) {
                    $user->following = max(0, $user->following - 1);
                    $user->save();
                }

                $user1->followers = max(0, $user1->followers - 1);
                $user1->save();

                $notificationQuery = SavedNotification::where('my_user_id', $request->user_id)
                    ->where('user_id', $request->my_user_id)
                    ->where('type', Constants::notificationTypeFollow);
                $companyActor
                    ? $notificationQuery->where('company_id', $companyActor->id)
                    : $notificationQuery->whereNull('company_id');
                $notificationQuery->delete();

                $followingList->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Unfollow user',
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'User Not Found in following list',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function checkUsername(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $username = InputSanitizer::sanitizeSearch($request->username, 50);
        $user = User::where('username', $username)->first();

        return response()->json([
            'status' => $user == null,
            'message' => $user == null ? 'Username is available' : 'Username is not available',
        ]);
    }

    public function fetchRandomProfile(Request $request)
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

        $user = User::where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not Found',
            ]);
        }

        $blockUserIds = array_filter(explode(',', $user->block_user_ids ?? ''));
        $interestsIds = array_filter(explode(',', $user->interest_ids ?? ''));

        shuffle($interestsIds);
        $randomUser = null;

        if (!empty($interestsIds)) {
            foreach ($interestsIds as $interestId) {
                $randomUser = User::whereNotIn('id', $blockUserIds)
                    ->where('is_block', 0)
                    ->where('id', '!=', $request->my_user_id)
                    ->whereNotNull('username')
                    ->whereNotNull('profile')
                    ->whereRaw('find_in_set(?, IFNULL(interest_ids, ""))', [(int) $interestId])
                    ->inRandomOrder()
                    ->first();

                if ($randomUser) {
                    break;
                }
            }
        }

        if (!$randomUser) {
            $randomUser = User::whereNotIn('id', $blockUserIds)
                                ->where('is_block', 0)
                                ->where('id', '!=', $request->my_user_id)
                                ->whereNotNull('username')
                                ->whereNotNull('profile')
                                ->inRandomOrder()
                                ->first();
        }

        return response()->json([
            'status' => true,
            'message' => 'Random profile found',
            'data' => $randomUser,
        ]);    
    }

    public function userReportList(Request $request)
    {
        $reportType = 2;
        $totalData = Report::where('type', $reportType)->count();
        $rows = Report::where('type', $reportType)
            ->with('user')
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
                ->with('user')
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $buildSearchQuery = function () use ($reportType, $search) {
                return Report::where('type', $reportType)
                    ->where(function ($query) use ($search) {
                        $query->where('reason', 'LIKE', "%{$search}%")
                            ->orWhere('desc', 'LIKE', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('full_name', 'LIKE', "%{$search}%")
                                    ->orWhere('identity', 'LIKE', "%{$search}%")
                                    ->orWhere('username', 'LIKE', "%{$search}%");
                            });
                    });
            };

            $result = $buildSearchQuery()
                ->with('user')
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $buildSearchQuery()->count();
        }

        $data = [];
        foreach ($result as $item) {
            $userData = $item->user;

            $imageUrl = ($userData && $userData->profile) ? $userData->profile : 'asset/image/default.png';
            $image = '<img src="' . $imageUrl . '" width="70" height="70" style="object-fit: cover;border-radius: 10px;box-shadow: 0px 10px 10px -8px #acacac;">';

            $fullName = $userData ? ($userData->full_name ?? 'Unknown user') : 'Unknown user';
            $identity = $userData ? ($userData->identity ?? 'Unknown identity') : 'Unknown identity';
            $description = $item->desc ?? 'No description';

            $rejectReport = '<a href="#" class="me-3 btn btn-orange px-4 text-white rejectReport d-flex align-items-center" rel=' . $item->id . ' data-tooltip="Reject Report" >' . __(' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-clipboard"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> <span class="ms-2"> Reject </span>') . '</a>';
            $delete = '<a href="#" class="btn btn-danger px-4 text-white delete blockUserBtn d-flex align-items-center " rel=' . $item->id . ' data-tooltip="Block User">' . __('<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg> <span class="ms-2"> Block User </span> ') . '</a>';
            $action = '<span class="float-right d-flex">' . $rejectReport . $delete . ' </span>';

            $data[] = [$image, $fullName, $identity, $item->reason, $description, $action];
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

    public function reportUser(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'user_id' => 'required|integer|exists:users,id|different:my_user_id',
            'reason' => 'required|string|max:500',
            'desc' => 'required|string|max:2000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $actor = User::where('id', (int) $request->my_user_id)
            ->where('is_block', 0)
            ->first();

        if (!$actor) {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $actor);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $user = User::where('id', $request->user_id)->first();

        if ($user != null) {
            if ($user->is_block == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already block',
                ]);
            }

            $report = new Report;
            $report->type = 2;
            $report->user_id = (int) $request->user_id;
            $report->reason = InputSanitizer::sanitizeText($request->reason, 500);
            $report->desc = InputSanitizer::sanitizeText($request->desc, 2000);
            $report->save();

            return response()->json([
                'status' => true,
                'message' => 'User Report Added Successfully',
                'data' => $report,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function fetchModerationAuditLogsAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
            'action' => 'nullable|string|max:80',
            'target_type' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:40',
            'moderator_user_id' => 'nullable|integer',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'keyword' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $query = ModerationAuditLog::query();
        $this->applyModerationAuditFilters($query, $request);

        $totalFiltered = (clone $query)->count();
        $rows = (clone $query)
            ->orderBy('id', 'DESC')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $rows,
            'meta' => [
                'start' => (int) $request->start,
                'limit' => (int) $request->limit,
                'total' => ModerationAuditLog::count(),
                'total_filtered' => $totalFiltered,
            ],
        ]);
    }

    public function exportModerationAuditLogsAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'nullable|in:json,csv',
            'max_rows' => 'nullable|integer|min:1|max:5000',
            'include_sensitive' => 'nullable|boolean',
            'legal_hold_reason' => 'nullable|string|max:500',
            'action' => 'nullable|string|max:80',
            'target_type' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:40',
            'moderator_user_id' => 'nullable|integer',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'keyword' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $includeSensitive = $this->normalizeBoolean($request->input('include_sensitive', false));
        if ($includeSensitive) {
            if (!$request->filled('legal_hold_reason')) {
                return response()->json([
                    'status' => false,
                    'message' => 'legal_hold_reason is required for sensitive export.',
                ]);
            }

            if (!config('moderation.allow_sensitive_export', false)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Sensitive export is disabled in this environment.',
                ], 403);
            }
        }

        $maxRows = (int) $request->input('max_rows', 1000);
        $format = (string) $request->input('format', 'json');

        $query = ModerationAuditLog::query();
        $this->applyModerationAuditFilters($query, $request);

        $rows = (clone $query)
            ->orderBy('id', 'DESC')
            ->take($maxRows)
            ->get();

        $exportRows = $this->buildModerationAuditExportRows($rows, $includeSensitive);

        if ($includeSensitive) {
            Log::notice('Sensitive moderation audit export requested.', [
                'rows' => count($exportRows),
                'legal_hold_reason' => InputSanitizer::sanitizeText((string) $request->legal_hold_reason, 500),
                'ip' => $request->ip(),
            ]);
        }

        if ($format === 'csv') {
            $csv = $this->generateModerationAuditCsv($exportRows);
            $filename = 'moderation_audit_export_' . Carbon::now()->format('Ymd_His') . '.csv';

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $exportRows,
            'meta' => [
                'row_count' => count($exportRows),
                'include_sensitive' => $includeSensitive,
                'exported_at' => Carbon::now()->toDateTimeString(),
                'format' => $format,
            ],
        ]);
    }

    public function submitModerationAppeal(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'audit_log_id' => 'required|integer',
            'reason' => 'required|string|max:500',
            'details' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $auditLog = ModerationAuditLog::find((int) $request->audit_log_id);
        if (!$auditLog) {
            return response()->json([
                'status' => false,
                'message' => 'Moderation audit log not found.',
            ]);
        }

        if ((int) ($auditLog->target_owner_user_id ?? 0) !== (int) $request->user_id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to appeal this moderation decision.',
                'error_code' => 'moderation_appeal_forbidden',
            ], 403);
        }

        $existingPendingAppeal = ModerationAuditAppeal::where('audit_log_id', (int) $request->audit_log_id)
            ->where('appellant_user_id', (int) $request->user_id)
            ->where('status', 'pending')
            ->first();

        if ($existingPendingAppeal) {
            return response()->json([
                'status' => false,
                'message' => 'A pending appeal already exists for this moderation decision.',
            ]);
        }

        $appeal = ModerationAuditAppeal::create([
            'audit_log_id' => (int) $request->audit_log_id,
            'appellant_user_id' => (int) $request->user_id,
            'status' => 'pending',
            'reason' => InputSanitizer::sanitizeText($request->reason, 500),
            'details' => InputSanitizer::sanitizeText($request->details, 2000),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Moderation appeal submitted successfully.',
            'data' => $appeal,
        ]);
    }

    public function fetchMyModerationAppeals(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $query = ModerationAuditAppeal::query()
            ->where('appellant_user_id', (int) $request->user_id)
            ->with(['auditLog:id,moderator_user_id,action,target_type,target_id,target_owner_user_id,status,created_at']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        $totalFiltered = (clone $query)->count();
        $rows = (clone $query)
            ->orderBy('id', 'DESC')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $rows,
            'meta' => [
                'start' => (int) $request->start,
                'limit' => (int) $request->limit,
                'total' => ModerationAuditAppeal::where('appellant_user_id', (int) $request->user_id)->count(),
                'total_filtered' => $totalFiltered,
            ],
        ]);
    }

    public function fetchModerationAppealsAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
            'status' => 'nullable|in:pending,approved,rejected',
            'audit_log_id' => 'nullable|integer',
            'appellant_user_id' => 'nullable|integer',
            'moderator_user_id' => 'nullable|integer',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $query = ModerationAuditAppeal::query()->with([
            'auditLog:id,moderator_user_id,action,target_type,target_id,target_owner_user_id,status,created_at',
        ]);
        $this->applyModerationAppealFilters($query, $request);

        $totalFiltered = (clone $query)->count();
        $rows = (clone $query)
            ->orderBy('id', 'DESC')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $rows,
            'meta' => [
                'start' => (int) $request->start,
                'limit' => (int) $request->limit,
                'total' => ModerationAuditAppeal::count(),
                'total_filtered' => $totalFiltered,
            ],
        ]);
    }

    public function reviewModerationAppealAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appeal_id' => 'required|integer',
            'decision' => 'required|in:approved,rejected',
            'resolution_note' => 'nullable|string|max:2000',
            'reviewed_by' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $appeal = ModerationAuditAppeal::where('id', (int) $request->appeal_id)->first();
        if (!$appeal) {
            return response()->json([
                'status' => false,
                'message' => 'Appeal not found.',
            ]);
        }

        if ($appeal->status !== 'pending') {
            return response()->json([
                'status' => false,
                'message' => 'Appeal already reviewed.',
            ]);
        }

        $appeal->status = (string) $request->decision;
        $appeal->resolution_note = InputSanitizer::sanitizeText($request->resolution_note, 2000);
        $appeal->reviewed_by = InputSanitizer::sanitizeSearch($request->input('reviewed_by', 'admin'), 120);
        $appeal->reviewed_at = Carbon::now();
        $appeal->save();

        Log::info('Moderation appeal reviewed.', [
            'appeal_id' => $appeal->id,
            'audit_log_id' => $appeal->audit_log_id,
            'decision' => $appeal->status,
            'reviewed_by' => $appeal->reviewed_by,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Appeal reviewed successfully.',
            'data' => $appeal,
        ]);
    }

    public function moderationAuditLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'nullable|string|max:80',
            'target_type' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:40',
            'moderator_user_id' => 'nullable|integer',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'keyword' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->route('moderationAuditLogs')->with('error', $validator->errors()->first());
        }

        $query = ModerationAuditLog::query();
        $this->applyModerationAuditFilters($query, $request);

        $perPage = (int) $request->input('per_page', 20);
        $filteredLogsCount = (clone $query)->count();

        $logs = (clone $query)
            ->orderBy('id', 'DESC')
            ->paginate($perPage)
            ->appends($request->query());

        $userIds = $logs->getCollection()->pluck('moderator_user_id')
            ->merge($logs->getCollection()->pluck('target_owner_user_id'))
            ->filter()
            ->unique()
            ->values();

        $userLookup = User::whereIn('id', $userIds)
            ->get(['id', 'full_name', 'username', 'identity'])
            ->keyBy('id');

        $kpis = [
            'total_logs' => ModerationAuditLog::count(),
            'logs_last_24h' => ModerationAuditLog::where('created_at', '>=', Carbon::now()->subDay())->count(),
            'unique_moderators_30d' => ModerationAuditLog::where('created_at', '>=', Carbon::now()->subDays(30))->distinct('moderator_user_id')->count('moderator_user_id'),
            'filtered_logs' => $filteredLogsCount,
        ];

        $actions = ModerationAuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $targetTypes = ModerationAuditLog::query()
            ->select('target_type')
            ->distinct()
            ->orderBy('target_type')
            ->pluck('target_type');

        return view('moderationAuditLogs', compact('logs', 'kpis', 'actions', 'targetTypes', 'userLookup'));
    }

    public function deleteUserReport(Request $request)
    {
        $report = Report::where('id', $request->report_id)->first();
        if ($report) {
            $userReports = Report::where('user_id', $report->user_id)->get();

            $userReports->each->delete();

            return response()->json([
                'status' => true,
                'message' => 'Report Delete Successfully',
                'data' => $userReports
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Report Not Found',
            ]);
        }
    }

    public function blockUserFromReport(Request $request)
    {
        $report = Report::where('id', $request->report_id)->first();

        if ($report) {

            $user = User::where('id', $report->user_id)->first();
            $user->is_block = 1;
            $user->save();

            $reportUsers = Report::where('user_id', $report->user_id)->get();
            $reportUsers->each->delete();

            return response()->json([
                'status' => true,
                'message' => 'User Added in Blocklist',
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
    }

    public function fetchPostByUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'my_user_id' => 'required',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->user_id)->first();
        if ($user) {
            $myUser = User::where('id', $request->my_user_id)->first();
            $companyActor = $myUser ? Company::where('id', $request->company_id)
                ->where('owner_user_id', $myUser->id)
                ->where('is_suspended', 0)
                ->first() : null;

            if ($request->filled('company_id') && !$companyActor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Company is not allowed to interact.',
                ]);
            }

            $fetchPosts = Post::where('user_id', $request->user_id)->with(['content', 'user', 'company', 'originalPost.content', 'originalPost.user', 'originalPost.company'])->orderBy('created_at', 'desc')->offset($request->start)->limit($request->limit)->get();

            // Hoist blocked-user query outside loop (was N+1)
            $blockUserIds = User::where('is_block', 1)->pluck('id');

            // Batch like check: single query for all post IDs
            $postIds = $fetchPosts->pluck('id');
            $likedPostIds = Like::where('user_id', $request->my_user_id)
                ->whereIn('post_id', $postIds)
                ->where('company_id', $companyActor ? $companyActor->id : null)
                ->pluck('post_id')
                ->toArray();

            foreach ($fetchPosts as $fetchPost) {
                $fetchPost->is_like = in_array($fetchPost->id, $likedPostIds) ? 1 : 0;

                $comments_count = Comment::whereNotIn('user_id', $blockUserIds)->where('post_id', $fetchPost->id)->count();
                $likes_count = Like::whereNotIn('user_id', $blockUserIds)->where('post_id', $fetchPost->id)->count();

                $fetchPost->comments_count = $comments_count;
                $fetchPost->likes_count = $likes_count;
            }

            return response()->json([
                'status' => true,
                'message' => 'Fetch post successfully',
                'data' => $fetchPosts,
            ]);
        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);
    }

    public function fetchProfile(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer',
            'user_id' => 'required|integer',
            'company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $profile = User::where('id', $request->user_id)->first();
        $viewer = User::where('id', $request->my_user_id)->first();

        if (!$profile) {
            return response()->json([
                'status' => false,
                'message' => 'Profile Not found',
            ]);
        }

        if (!$viewer) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $companyActor = $this->resolveCompanyActor($request, $viewer);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        if ($companyActor) {
            $iFollowThem = FollowingList::where('my_user_id', $viewer->id)
                ->where('company_id', $companyActor->id)
                ->where('user_id', $profile->id)
                ->exists();
            $theyFollowMe = DB::table('company_followers')
                ->where('company_id', $companyActor->id)
                ->where('user_id', $profile->id)
                ->whereNull('follower_company_id')
                ->exists();
        } else {
            // Single query to determine mutual follow status (was 2 separate queries)
            $followRecords = FollowingList::whereRelation('user', 'is_block', 0)
                ->whereNull('company_id')
                ->where(function ($q) use ($request) {
                    $q->where(function ($q2) use ($request) {
                        $q2->where('user_id', $request->my_user_id)
                           ->where('my_user_id', $request->user_id);
                    })->orWhere(function ($q2) use ($request) {
                        $q2->where('my_user_id', $request->my_user_id)
                           ->where('user_id', $request->user_id);
                    });
                })
                ->get();

            $theyFollowMe = $followRecords->contains(function ($f) use ($request) {
                return $f->user_id == $request->my_user_id && $f->my_user_id == $request->user_id;
            });
            $iFollowThem = $followRecords->contains(function ($f) use ($request) {
                return $f->my_user_id == $request->my_user_id && $f->user_id == $request->user_id;
            });
        }

        if ($theyFollowMe && $iFollowThem) {
            $profile->followingStatus = 3;
        } elseif ($iFollowThem) {
            $profile->followingStatus = 2;
        } elseif ($theyFollowMe) {
            $profile->followingStatus = 1;
        } else {
            $profile->followingStatus = 0;
        }

        // Stories from last 24h
        $profile->stories = Story::where('user_id', $request->user_id)
            ->whereNull('company_id')
            ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())
            ->with(['user', 'company'])
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        // Interests — use cached interests when possible
        $interestIds = array_filter(explode(',', $profile->interest_ids ?? ''));
        if (!empty($interestIds)) {
            $profile->interest = Interest::whereIn('id', $interestIds)->get();
        } else {
            $profile->interest = collect();
        }

        $ownedCompany = Company::where('owner_user_id', $profile->id)
            ->where('is_suspended', 0)
            ->first();

        $profile->company_stories = collect();
        if ($ownedCompany) {
            $profile->company_stories = Story::where('company_id', $ownedCompany->id)
                ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->with(['user', 'company'])
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->get();
        }

        if ($ownedCompany) {
            $ownedCompany->followers_count = DB::table('company_followers')
                ->where('company_id', $ownedCompany->id)
                ->count();
            if ($companyActor) {
                $ownedCompany->is_following = (int) $companyActor->id === (int) $ownedCompany->id
                    ? 0
                    : (DB::table('company_followers')
                        ->where('company_id', $ownedCompany->id)
                        ->where('user_id', $request->my_user_id)
                        ->where('follower_company_id', $companyActor->id)
                        ->exists() ? 1 : 0);
            } else {
                $ownedCompany->is_following = DB::table('company_followers')
                    ->where('company_id', $ownedCompany->id)
                    ->where('user_id', $request->my_user_id)
                    ->whereNull('follower_company_id')
                    ->exists() ? 1 : 0;
            }
            $ownedCompany->published_offers_count = $ownedCompany->publishedOffers()->count();
            $ownedCompany->job_offers_count = $ownedCompany->jobOffers()->count();
        }

        $profile->profile_type = $ownedCompany ? 'company' : 'user';
        $profile->owned_company = $ownedCompany;

        return response()->json([
            'status' => true,
            'message' => 'Getting profile successfully',
            'data' => $profile,
        ]);
    }

    public function deleteUser(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authErr;
        }

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $userPosts = Post::where('user_id', $request->user_id)->get();

        foreach ($userPosts as $userPost) {
            foreach ($userPost as $userOnlyOnePost) {
                $userOnlyOnePost = PostContent::where('post_id', $userPost->id)->first();
                if($userOnlyOnePost != null) {
                    GlobalFunction::deleteFile($userOnlyOnePost->content);
                    GlobalFunction::deleteFile($userOnlyOnePost->thumbnail);
                    $userOnlyOnePost->delete();
                }
            }
            $myComments = Comment::where('post_id', $userPost->id)->get();
            foreach ($myComments as $myComment) {
                $myComment->delete();
            }
            $myPostsLikes = Like::where('post_id', $userPost->id)->get();
            foreach ($myPostsLikes as $myPostsLike) {
                $myPostsLike->delete();
            }
        }

        Comment::where('user_id', $request->user_id)->delete();

        $userLikes = Like::where('user_id', $request->user_id)->get();

        foreach ($userLikes as $userLike) {
            $removeLikeFromPost = Post::where('id', $userLike->post_id)->first();
            if ($removeLikeFromPost != null) {
                $removeLikeFromPost->likes_count = max(0, $removeLikeFromPost->likes_count - 1);
                $removeLikeFromPost->save();
            }
            $userLike->delete();
        }

        $userfollowings = FollowingList::where('my_user_id', $request->user_id)->get();
        foreach ($userfollowings as $userfollowing) {
            $userFollowers = User::where('id', $userfollowing->user_id)->first();
            $userFollowers->followers = max(0, $userFollowers->followers - 1);
            $userFollowers->save();
            $userfollowing->delete();
        }

        $removefollowings = FollowingList::where('user_id', $request->user_id)->get();
        foreach ($removefollowings as $removefollowing) {
            $removeUserFollowing = User::where('id', $removefollowing->my_user_id)->first();
            $removeUserFollowing->following = max(0, $removeUserFollowing->following - 1);
            $removeUserFollowing->save();
            $removefollowing->delete();
        }

        $userPosts->each->delete();

        Story::where('user_id',$request->user_id)->delete();
        Report::where('user_id', $request->user_id)->delete();
        ProfileVerification::where('user_id', $request->user_id)->delete();

        $deleteRooms = Room::where('admin_id', $request->user_id)->get();
        foreach ($deleteRooms as $deleteRoom) {
            if ($deleteRoom->photo != null) {
                GlobalFunction::deleteFile($deleteRoom->photo);
            }
            RoomUser::where('room_id',  $deleteRoom->id)->delete();
            $deleteRoom->delete();
        }

        $reels = Reel::where('user_id', $request->user_id)->get();

        foreach ($reels as $reel) {
            $reelComments = ReelComment::where('reel_id', $reel->id)->get();
            foreach ($reelComments as $reelComment) {
                SavedNotification::where('reel_comment_id', $reelComment->id)->delete();
                $reelComment->delete();
            }

            Like::where('reel_id', $reel->id)->delete();

            GlobalFunction::deleteFile($reel->content);
            GlobalFunction::deleteFile($reel->thumbnail);

            SavedNotification::where('reel_id', $reel->id)->delete();

            $reel->delete();
        }

        RoomUser::where('user_id', $request->user_id)->delete();
        SavedNotification::where('user_id', $request->user_id)->delete();
        SavedNotification::where('my_user_id', $request->user_id)->delete();
        GlobalFunction::deleteFile($user->profile);
        GlobalFunction::deleteFile($user->background_image);
        
        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    public function searchProfile(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
            'keyword' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ]);
        }

        $myProfile = User::find($request->my_user_id);
        if (!$myProfile) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ]);
        }

        $blockUserIds = array_filter(explode(',', $myProfile->block_user_ids ?? ''));
        $keyword = trim($request->keyword ?? '');
        $myInterests = array_filter(explode(',', $myProfile->interest_ids ?? ''));

        $query = User::where('is_block', 0)
                    ->where('id', '!=', $request->my_user_id)
                    ->whereNotIn('id', $blockUserIds)
                    ->whereNotNull('username')
                    ->whereNotNull('profile');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('full_name', 'like', "%{$keyword}%")
                  ->orWhere('headline', 'like', "%{$keyword}%")
                  ->orWhere('skills', 'like', "%{$keyword}%");
            });
        }

        // Randomization seed: changes every 5 minutes so repeated searches show different profiles
        $seed = intval(time() / 300);

        // Tiered ranking:
        // Tier 1: interest_score (exact shared interest count, highest first)
        // Tier 2: verified status (is_verified >= 2)
        // Tier 3: activity recency (updated_at within last 7 days = active)
        // Tier 4: follower count
        // Tier 5: randomization for variety
        $activityExpr = "IF(updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY), 1, 0)";

        if (!empty($myInterests)) {
            // Build a score expression: count shared interests using FIND_IN_SET
            $scoreParts = [];
            foreach ($myInterests as $interestId) {
                $interestId = intval($interestId);
                $scoreParts[] = "IF(FIND_IN_SET('{$interestId}', IFNULL(interest_ids, '')) > 0, 1, 0)";
            }
            $scoreExpr = '(' . implode(' + ', $scoreParts) . ')';

            $query->selectRaw("users.*, {$scoreExpr} as interest_score, {$activityExpr} as is_active")
                  ->orderByDesc('interest_score')
                  ->orderByDesc(\DB::raw('CASE WHEN is_verified >= 2 THEN 1 ELSE 0 END'))
                  ->orderByDesc('is_active')
                  ->orderByDesc('followers')
                  ->orderByRaw("RAND({$seed})");
        } else {
            $query->selectRaw("users.*, {$activityExpr} as is_active")
                  ->orderByDesc(\DB::raw('CASE WHEN is_verified >= 2 THEN 1 ELSE 0 END'))
                  ->orderByDesc('is_active')
                  ->orderByDesc('followers')
                  ->orderByRaw("RAND({$seed})");
        }

        $candidateLimit = min(((int) $request->start + (int) $request->limit), 500);

        $users = $query->limit($candidateLimit)
                       ->get();

        $users->makeHidden(['interest_score', 'is_active']);

        $userResults = $users->map(function ($user) use ($keyword) {
            $name = strtolower((string) ($user->full_name ?? ''));
            $username = strtolower((string) ($user->username ?? ''));
            $needle = strtolower($keyword);
            $score = 0;
            if ($needle !== '') {
                if ($name === $needle || $username === $needle) {
                    $score += 100;
                } elseif (strpos($name, $needle) === 0 || strpos($username, $needle) === 0) {
                    $score += 60;
                } elseif (strpos($name . ' ' . $username, $needle) !== false) {
                    $score += 30;
                }
            }
            $score += ((int) $user->is_verified >= 2) ? 20 : 0;
            $score += min((int) ($user->followers ?? 0), 100000) / 10000;

            $item = $user->toArray();
            $item['profile_type'] = 'user';
            $item['owned_company'] = null;
            $item['_search_score'] = $score;
            return $item;
        });

        $companyQuery = Company::where('is_verified', 1)
            ->where('is_suspended', 0);

        if (!empty($keyword)) {
            $companyQuery->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('sector', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('city', 'like', "%{$keyword}%")
                    ->orWhere('country', 'like', "%{$keyword}%");
            });
        }

        $companyResults = $companyQuery
            ->orderByDesc('is_verified')
            ->orderBy('name')
            ->limit($candidateLimit)
            ->get()
            ->map(function ($company) use ($keyword) {
                $name = strtolower((string) ($company->name ?? ''));
                $sector = strtolower((string) ($company->sector ?? ''));
                $needle = strtolower($keyword);
                $score = 10;
                if ($needle !== '') {
                    if ($name === $needle) {
                        $score += 110;
                    } elseif (strpos($name, $needle) === 0) {
                        $score += 70;
                    } elseif (strpos($name . ' ' . $sector, $needle) !== false) {
                        $score += 35;
                    }
                }
                $score += min((int) DB::table('company_followers')->where('company_id', $company->id)->count(), 100000) / 10000;

                $item = $this->companyAsUserListItem($company);
                $item['_search_score'] = $score;
                return $item;
            });

        $results = $userResults
            ->concat($companyResults)
            ->sortByDesc(function ($item) {
                return $item['_search_score'] ?? 0;
            })
            ->slice((int) $request->start, (int) $request->limit)
            ->values()
            ->map(function ($item) {
                unset($item['_search_score']);
                return $item;
            });

        return response()->json([
            'status' => true,
            'message' => 'User profile',
            'data' => $results
        ]);
    }

    public function fetchBlockedUserList(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        if($user) {
            $blockUserIds = explode(',', $user->block_user_ids);

            $blockedUser = User::whereIn('id', $blockUserIds)->get();
            return response()->json([
                'status' => true,
                'message' => 'Fetch blocked user list successfully',
                'data' => $blockedUser
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ]);
        }
    }

    public function logOut(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authErr;
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
        if($user) {

            $token = $request->bearerToken() ?: $request->header('authtoken');
            if ($token) {
                $accessToken = PersonalAccessToken::findToken((string) $token);
                if ($accessToken && $accessToken->tokenable instanceof User && (int) $accessToken->tokenable->id === (int) $user->id) {
                    $accessToken->delete();
                }
            }

            $user->device_token = null;
            $user->save();
            return response()->json([
                'status' => true,
                'message' => 'User logout successfully',
                'data' => $user,
            ]);

        }
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ]);



    }

    public function editProfileFormWeb(Request $request)
    {
        $user = User::where('id', $request->user_id)->first();
        if ($user) {
            if ($request->has('username')) {
                $user->username = $request->username;
            }
            if ($request->hasFile('profile')) {
                $path = GlobalFunction::deleteFile($user->profile);
                $file = $request->file('profile');
                $path = GlobalFunction::saveFileAndGivePath($file);
                $user->profile = $path;
            }
            if ($request->hasFile('background_image')) {
                GlobalFunction::deleteFile($user->background_image);
                $file = $request->file('background_image');
                $path = GlobalFunction::saveFileAndGivePath($file);
                $user->background_image = $path;
            }
            if ($request->has('bio')) {
                $user->bio = $request->bio;
            }
            if ($request->has('full_name')) {
                $user->full_name = $request->full_name;
            }
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'User Updated Successfully',
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'User Not Found',
            ]);
        }
    }

    public function UserBlockedByUser(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $fromUser = User::where('id', $request->my_user_id)->first();
        if ($fromUser == null) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $toUser = User::where('id', $request->user_id)->first();
        if ($toUser == null) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $fetchFollowingUsers = FollowingList::where('my_user_id', $request->my_user_id)
            ->where('user_id', $request->user_id)
            ->whereNull('company_id')
            ->first();
        if ($fetchFollowingUsers != null) {
            $followingCount = User::where('id', $request->my_user_id)->first();
            $followingCount->following = max(0, $followingCount->following - 1);
            $followingCount->save();

            $followersCount = User::where('id', $request->user_id)->first();
            $followersCount->followers = max(0, $followersCount->followers - 1);
            $followersCount->save();

            $fetchFollowingUsers->delete();
        }


        $fetchFollowerUsers = FollowingList::where('user_id', $request->my_user_id)
            ->where('my_user_id', $request->user_id)
            ->whereNull('company_id')
            ->first();
        if ($fetchFollowerUsers != null) {
            $followersCount = User::where('id', $request->my_user_id)->first();
            $followersCount->followers = max(0, $followersCount->followers - 1);
            $followersCount->save();

            $followingCount = User::where('id', $request->user_id)->first();
            $followingCount->following = max(0, $followingCount->following - 1);
            $followingCount->save();

            $fetchFollowerUsers->delete();
        }

        $blockUserIds = explode(',', $fromUser->block_user_ids);
        foreach ($blockUserIds as $blockUserId) {
            if ($blockUserId == $request->user_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'User already Blocked'
                ]);
            }
        }

        $fromUser->block_user_ids = $fromUser->block_user_ids . $request->user_id . ',';
        $fromUser->save();

        $userNotification = SavedNotification::where('my_user_id', $request->my_user_id)
                                             ->where('type', Constants::notificationTypeFollow)
                                             ->get();
        $userNotification->each->delete();

        return response()->json([
            'status' => true,
            'message' => 'User Block Successfully',
            'data' => $toUser
        ]);
    }

    public function UserUnblockedByUser(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $fromUser = User::where('id', $request->my_user_id)->first();
        if ($fromUser == null) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $toUser = User::where('id', $request->user_id)->first();
        if ($toUser == null) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $blockUserIds = explode(',', $fromUser->block_user_ids);
        foreach (array_keys($blockUserIds, $request->user_id) as $key) {
            unset($blockUserIds[$key]);
        }
        $fromUser->block_user_ids = implode(",", $blockUserIds);
        $fromUser->save();

        return response()->json([
            'status' => true,
            'message' => 'User Unblock Successfully',
            'data' => $fromUser
        ]);


    }

    public function fetchUserNotification(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $start = (int) $request->start;
        $limit = (int) $request->limit;

        $savedNotification = SavedNotification::where('my_user_id', (int) $request->my_user_id)
                                            ->with(['user', 'company', 'post.content', 'post.company', 'room.company', 'reel.company'])
                                            ->when($companyActor, function ($query) use ($companyActor) {
                                                $this->applyCompanyNotificationScope($query, $companyActor->id);
                                            }, function ($query) {
                                                $this->applyPersonalNotificationScope($query);
                                            })
                                            ->offset($start)
                                            ->limit($limit)
                                            ->orderBy('created_at', 'desc')
                                            ->orderBy('id', 'desc')
                                            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Saved Notification Successfully',
            'data' => $savedNotification,
            'meta' => [
                'start' => $start,
                'limit' => $limit,
                'count' => $savedNotification->count(),
                'has_more' => $savedNotification->count() >= $limit,
            ],
        ]);

    }

    public function markNotificationsAsRead(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $updatedCount = SavedNotification::where('my_user_id', (int) $request->my_user_id)
            ->when($companyActor, function ($query) use ($companyActor) {
                $this->applyCompanyNotificationScope($query, $companyActor->id);
            }, function ($query) {
                $this->applyPersonalNotificationScope($query);
            })
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => true,
            'message' => 'All notifications marked as read',
            'data' => [
                'updated_count' => (int) $updatedCount,
            ],
        ]);
    }

    public function fetchUnreadNotificationCount(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request, 'my_user_id')) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'my_user_id' => 'required|integer|exists:users,id',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $user = User::where('id', $request->my_user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }

        $companyActor = $this->resolveCompanyActor($request, $user);
        if ($companyActor instanceof \Illuminate\Http\JsonResponse) {
            return $companyActor;
        }

        $count = SavedNotification::where('my_user_id', (int) $request->my_user_id)
            ->when($companyActor, function ($query) use ($companyActor) {
                $this->applyCompanyNotificationScope($query, $companyActor->id);
            }, function ($query) {
                $this->applyPersonalNotificationScope($query);
            })
            ->where('is_read', false)
            ->count();

        return response()->json([
            'status' => true,
            'message' => 'Unread Notification Count',
            'data' => [
                'count' => (int) $count,
                'unread_count' => (int) $count,
            ],
        ]);
    }

    public function updateModeratorStatus(Request $request)
    {
        $user = User::where('id', $request->id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Room not found',
            ]);
        }

        if($user->is_push_notifications == 1) {
            if ($request->is_moderator == 1) {
                $notificationDesc = 'You are Moderator now.';
                GlobalFunction::sendPushNotificationToUser($notificationDesc, $user->device_token, $user->device_type);
            }
        }

        $user->is_moderator = $request->is_moderator;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'User Updated successfully',
        ]);
      
    }

    public function deletePostByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'post_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $post = Post::find($request->post_id);
        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post Not Found',
            ]);
        }

        $postContents = PostContent::where('post_id', $request->post_id)->get();
        foreach ($postContents as $postContent) {
            GlobalFunction::deleteFile($postContent->content);
            GlobalFunction::deleteFile($postContent->thumb);
        }
        $postContents->each->delete();

        Comment::where('post_id', $request->post_id)->delete();
        Like::where('post_id', $request->post_id)->delete();
        SavedNotification::where('post_id', $request->post_id)->delete();
        Report::where('post_id', $request->post_id)->where('type', 1)->delete();

        $post->delete();
        $this->recordModerationAudit($request, 'delete_post', 'post', $post->id, $post->user_id, [
            'comments_count' => $post->comments_count,
            'likes_count' => $post->likes_count,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Post Deleted Successfully',
        ]);
    }

    public function deleteCommentByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'comment_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $comment = Comment::where('id', $request->comment_id)->first();

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found'
            ]);
        }

        $replyCount = Comment::where('parent_id', $comment->id)->count();
        Comment::where('parent_id', $comment->id)->delete();

        $post = Post::where('id', $comment->post_id)->first();
        if ($post) {
            $post->comments_count = max(0, $post->comments_count - 1 - $replyCount);
            $post->save();
        }

        SavedNotification::where('user_id', $request->user_id)
                            ->where('post_id', $comment->post_id)
                            ->where('type', Constants::notificationTypeComment)
                            ->delete();
        
        $comment->delete();
        $this->recordModerationAudit($request, 'delete_comment', 'comment', $comment->id, $comment->user_id, [
            'post_id' => $comment->post_id,
            'reply_count' => $replyCount,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Comment Delete successfully',
            'data' => $comment
        ]);


        
    }

    public function deleteRoomByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'room_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
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
        Report::where('room_id', $request->room_id)->where('type', 1)->delete();

        $room->delete();
        $this->recordModerationAudit($request, 'delete_room', 'room', $room->id, $room->admin_id, [
            'title' => $room->title,
            'total_member' => $room->total_member,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Room deleted successfully',
            'data' => $room,
        ]);
    }

    public function deleteStoryByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'story_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $story = Story::where('id', $request->story_id)->first();
        if (!$story) {
            return response()->json([
                'status' => false,
                'message' => 'Story not found',
            ]);
        }

        GlobalFunction::deleteFile($story->content);

        $story->delete();
        $this->recordModerationAudit($request, 'delete_story', 'story', $story->id, $story->user_id ?? null, [
            'company_id' => $story->company_id ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Story deleted successfully',
            'data' => $story,
        ]);
    }

    public function userBlockByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'to_user_id' => 'required|integer|different:user_id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $toUser = User::where('id', $request->to_user_id)->first();
        
        if (!$toUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        if ($request->user_id == $request->to_user_id) {
            return response()->json([
                'status' => false,
                'message' => 'Lol, You can not block yourself',
            ]);
        }

        $previousIsBlock = $toUser->is_block;
        $toUser->is_block = Constants::blocked;
        $toUser->save();

        $reportUsers = Report::where('user_id', $request->to_user_id)->get();
        $reportUsers->each->delete();
        $this->recordModerationAudit($request, 'block_user', 'user', $toUser->id, $toUser->id, [
            'previous_is_block' => $previousIsBlock,
            'new_is_block' => Constants::blocked,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User Blocked successfully',
            'data' => $toUser,
        ]);
    }

    public function deleteReelCommentByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reel_comment_id' => 'required|exists:reel_comments,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $reelComment = ReelComment::where('id', $request->reel_comment_id)->first();
        if (!$reelComment) {
            return response()->json([
                'status' => false,
                'message' => 'Reel Comment not found.',
            ]);
        }

        $replyCount = ReelComment::where('parent_id', $reelComment->id)->count();
        ReelComment::where('parent_id', $reelComment->id)->delete();

        $reel = Reel::find($reelComment->reel_id);
        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel not found.',
            ]);
        }

        $reel->comments_count = max(0, $reel->comments_count - 1 - $replyCount);
        $reel->save();

        SavedNotification::where('reel_comment_id', $request->reel_comment_id)
                        ->where('type', Constants::notificationTypeAddReelComment)
                        ->delete();

        $reelComment->delete();
        $this->recordModerationAudit($request, 'delete_reel_comment', 'reel_comment', $reelComment->id, $reelComment->user_id, [
            'reel_id' => $reelComment->reel_id,
            'reply_count' => $replyCount,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Reel Comment Delete Successfully By Moderator.',
            'data' => $reelComment,
        ]);
    }

    public function deleteReelByModerator(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reel_id' => 'required|exists:reels,id',
        ]);

        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $reel = Reel::find($request->reel_id);
        if (!$reel) {
            return response()->json([
                'status' => false,
                'message' => 'Reel not found.',
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

        Report::where('reel_id', $request->reel_id)->delete();

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
        $this->recordModerationAudit($request, 'delete_reel', 'reel', $reel->id, $reel->user_id, [
            'company_id' => $reel->company_id,
            'comments_count' => $reel->comments_count,
            'likes_count' => $reel->likes_count,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Reel Deleted Successfully By Moderator.',
            'data' => $reel,
        ]);
    }

    public function deleteAvatarFromUserDetail(Request $request)
    {
        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        GlobalFunction::deleteFile($user->background_image);
        $user->background_image = null;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Delete Background Image',
            'data' => $user,
        ]);
    }

    public function deleteProfileFromUserDetail(Request $request)
    {
        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        GlobalFunction::deleteFile($user->profile);
        $user->profile = null;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Delete Background Image',
            'data' => $user,
        ]);
    }
}
