<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ((int) $request->session()->get('user_type') === 1) {
            return $next($request);
        }

        $message = 'Only super administrators can perform this action.';

        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse([
                'status' => false,
                'message' => $message,
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }
}
