<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing Authorization Bearer token'
            ], 401);
        }

        $token = trim(substr($header, 7)); // після "Bearer "

        if ($token !== config('app.api_token_jwt')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid token'
            ], 401);
        }

        return $next($request);
    }
}
