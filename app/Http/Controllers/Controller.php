<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function enforceAuthenticatedUserField(Request $request, string $field = 'user_id')
    {
        $authenticatedUserId = (int) $request->attributes->get('authenticated_user_id');
        if ($authenticatedUserId <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized Access',
                'error_code' => 'auth_context_missing',
            ], 401);
        }

        if ($request->filled($field) && (int) $request->input($field) !== $authenticatedUserId) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to act for another user.',
                'error_code' => 'authenticated_user_mismatch',
            ], 403);
        }

        $request->merge([$field => $authenticatedUserId]);

        return null;
    }

    /**
     * Company owner authorization for private company actions (dashboard, edit, jobs, etc.).
     *
     * Every company must have an owner_user_id (created or linked at login / verify).
     * The authenticated user (Bearer token + enforceAuthenticatedUserField) must match
     * that owner — never trust user_id from the body alone without middleware.
     */
    protected function companyOwnerAuthorizationError($company, Request $request)
    {
        $ownerId = (int) ($company->owner_user_id ?? 0);
        if ($ownerId <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'This company account must be linked to an ITGA user profile. Please log in again on the company portal to complete setup.',
                'error_code' => 'company_owner_migration_required',
            ]);
        }

        if (!$request->filled('user_id')) {
            return response()->json([
                'status' => false,
                'message' => 'Linked ITGA user is required for this company action.',
                'error_code' => 'company_owner_required',
            ]);
        }

        if ((int) $request->user_id !== $ownerId) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to manage this company.',
                'error_code' => 'company_owner_mismatch',
            ]);
        }

        return null;
    }

    protected function issueUserApiToken(User $user, Request $request): ?string
    {
        $deviceType = $request->input('device_type', 'unknown');
        $deviceName = is_scalar($deviceType) ? (string) $deviceType : 'unknown';

        try {
            return $user->createToken('itga-api-' . $deviceName)->plainTextToken;
        } catch (Throwable $exception) {
            Log::warning('API token issuance failed.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }
}
