<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a Sanctum API user when a bearer token is present, without requiring authentication.
 */
final class AttemptSanctumAuthentication
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->bearerToken() !== null) {
            Auth::guard('api')->user();
        }

        return $next($request);
    }
}
