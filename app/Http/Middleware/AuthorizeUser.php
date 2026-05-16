<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthorizeUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->header('authtoken');

        if (!$token) {
            return $this->unauthorized('Token Not Provided', 'token_not_provided');
        }

        $accessToken = $this->findAccessToken((string) $token);
        if (!$accessToken || !$accessToken->tokenable instanceof User) {
            return $this->unauthorized('Unauthorized Access', 'user_not_found');
        }

        $user = $accessToken->tokenable;
        if ((int) ($user->is_block ?? 0) === 1) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Account blocked.',
                'error_code' => 'account_blocked',
            ], 403);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $user->withAccessToken($accessToken));
        $request->attributes->set('authenticated_user_id', $user->id);

        return $next($request);
    }

    protected function findAccessToken(string $token)
    {
        return PersonalAccessToken::findToken($token);
    }

    private function unauthorized(string $reason, string $errorCode): JsonResponse
    {
        return new JsonResponse([
            'status' => false,
            'message' => 'Unauthorized Access',
            'reason' => $reason,
            'error_code' => $errorCode,
        ], 401);
    }
}
