<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckAdminHeader
{
    public function handle(Request $request, Closure $next)
    {
        $adminKey = $request->header('x-admin-key') ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? null);
        $expectedAdminKey = getenv('ADMIN_API_SECRET_KEY')
            ?: ($_ENV['ADMIN_API_SECRET_KEY'] ?? ($_SERVER['ADMIN_API_SECRET_KEY'] ?? config('ops.admin_api_secret_key', '')));

        if (empty($expectedAdminKey)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Admin API key is not configured.',
            ], 500);
        }

        if (!empty($adminKey) && hash_equals($expectedAdminKey, $adminKey)) {
            return $next($request);
        }

        return new JsonResponse([
            'status' => false,
            'message' => 'Unauthorized Admin Access',
        ], 403);
    }
}
