<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Constants;
use App\Models\Room;
use App\Models\RoomUser;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoomSuggestionService
{
    public function forViewer(User $viewer, ?Company $companyActor = null, int $limit = 2): Collection
    {
        $interestIds = $this->viewerInterestIds($viewer, $companyActor);
        if (empty($interestIds)) {
            return collect();
        }

        $myRoomIdsQuery = RoomUser::where('user_id', $viewer->id);
        $this->applyRoomUserActorScope($myRoomIdsQuery, $companyActor);
        $myRoomIds = $myRoomIdsQuery->pluck('room_id');

        $blockedUserIds = User::where('is_block', 1)->pluck('id');
        $roomUsersLimit = (int) (optional(Setting::first())->setRoomUsersLimit ?: 500);

        $query = Room::with(['user', 'company'])
            ->where('admin_id', '!=', $viewer->id)
            ->where('is_private', 0)
            ->where('total_member', '<', $roomUsersLimit)
            ->whereNotIn('id', $myRoomIds)
            ->whereNotIn('admin_id', $blockedUserIds)
            ->where(function ($verifiedQuery) {
                $verifiedQuery
                    ->whereHas('user', function ($userQuery) {
                        $userQuery
                            ->where('is_block', 0)
                            ->where('is_verified', '>=', Constants::is_verified);
                    })
                    ->orWhereHas('company', function ($companyQuery) {
                        $companyQuery
                            ->where('is_suspended', 0)
                            ->where('is_verified', 1);
                    });
            });

        $this->applyInterestFilter($query, $interestIds);

        $rooms = $query
            ->orderByDesc('total_member')
            ->latest('created_at')
            ->limit(max(1, $limit))
            ->get();

        if ($rooms->isEmpty()) {
            return $rooms;
        }

        $statusesQuery = RoomUser::where('user_id', $viewer->id)
            ->whereIn('room_id', $rooms->pluck('id'));
        $this->applyRoomUserActorScope($statusesQuery, $companyActor);
        $statuses = $statusesQuery->pluck('type', 'room_id');

        $rooms->each(function ($room) use ($statuses) {
            $room->userRoomStatus = (int) ($statuses[$room->id] ?? 0);
        });

        return $rooms->values();
    }

    private function viewerInterestIds(User $viewer, ?Company $companyActor = null): array
    {
        $companyInterests = $companyActor && isset($companyActor->interest_ids)
            ? $companyActor->interest_ids
            : null;

        $interestIds = $this->normalizeInterestIds($companyInterests);
        if (!empty($interestIds)) {
            return $interestIds;
        }

        return $this->normalizeInterestIds($viewer->interest_ids);
    }

    private function normalizeInterestIds($raw): array
    {
        if (empty($raw)) {
            return [];
        }

        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function applyInterestFilter($query, array $interestIds): void
    {
        $driver = DB::connection()->getDriverName();

        $query->where(function ($interestQuery) use ($interestIds, $driver) {
            foreach ($interestIds as $interestId) {
                if ($driver === 'sqlite') {
                    $interestQuery
                        ->orWhere('interest_ids', (string) $interestId)
                        ->orWhere('interest_ids', 'like', $interestId . ',%')
                        ->orWhere('interest_ids', 'like', '%,' . $interestId)
                        ->orWhere('interest_ids', 'like', '%,' . $interestId . ',%');
                } else {
                    $interestQuery->orWhereRaw('find_in_set(?, interest_ids)', [$interestId]);
                }
            }
        });
    }

    private function applyRoomUserActorScope($query, ?Company $companyActor = null): void
    {
        if ($companyActor) {
            $query->where('company_id', $companyActor->id);
            return;
        }

        $query->where(function ($actorQuery) {
            $actorQuery
                ->whereNull('company_id')
                ->orWhere('company_id', 0);
        });
    }
}
