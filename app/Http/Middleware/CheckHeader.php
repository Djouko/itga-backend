<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckHeader
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
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $apikey = $request->header('apikey') ?? ($_SERVER['HTTP_APIKEY'] ?? null);
        $expectedKey = getenv('API_SECRET_KEY')
            ?: ($_ENV['API_SECRET_KEY'] ?? ($_SERVER['API_SECRET_KEY'] ?? config('ops.api_secret_key', '')));

        if (!empty($apikey) && !empty($expectedKey) && hash_equals($expectedKey, $apikey)) {
            return $next($request);
        }

        return new JsonResponse(['status' => false, 'message' => 'Unauthorized Access'], 401);
    }
}
