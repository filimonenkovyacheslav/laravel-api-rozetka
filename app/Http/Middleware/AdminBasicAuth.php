<?php

namespace App\Http\Middleware;

use Closure;

class AdminBasicAuth
{
    public function handle($request, Closure $next)
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

        $expectedUser = env('ADMIN_USER', 'admin');
        $expectedPass = env('ADMIN_PASS', 'change_me');

        if ($user !== $expectedUser || $pass !== $expectedPass) {
            header('WWW-Authenticate: Basic realm="Admin Area"');
            header('HTTP/1.0 401 Unauthorized');
            exit('Unauthorized');
        }

        return $next($request);
    }
}
