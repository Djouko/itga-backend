<?php

namespace App\Http\Middleware;

use App\Services\ModerationActionPolicy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureModeratorAction
{
    public function __construct(private ModerationActionPolicy $policy)
    {
    }

    public function handle(Request $request, Closure $next, string $action)
    {
        if (!$this->policy->isKnown($action)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Unsupported moderation action policy.',
            ], 403);
        }

        if (!$this->policy->isEnabled($action)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'This moderation action is currently disabled.',
            ], 403);
        }

        $request->attributes->set('moderation_action_key', $action);

        return $next($request);
    }
}
