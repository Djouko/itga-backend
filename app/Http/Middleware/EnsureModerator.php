<?php

namespace App\Http\Middleware;

use App\Models\Constants;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureModerator
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return new JsonResponse([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        if ((int) $user->is_moderator !== Constants::moderator) {
            return new JsonResponse([
                'status' => false,
                'message' => 'User is not a Moderator',
            ]);
        }

        return $next($request);
    }
}
