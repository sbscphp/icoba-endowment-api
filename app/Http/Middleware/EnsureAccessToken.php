<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessToken
{
    /**
     * Reject Sanctum tokens that are refresh-only (not valid for API access).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken && $this->isRefreshToken($token)) {
                throw new AuthenticationException('Unauthenticated.');
            }
        }

        return $next($request);
    }

    private function isRefreshToken(PersonalAccessToken $token): bool
    {
        if (str_ends_with((string) $token->name, ':refresh')) {
            return true;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];

        $hasRefreshAbility = in_array('customer:refresh', $abilities, true)
            || in_array('admin:refresh', $abilities, true);
        $hasAccessAbility = in_array('customer', $abilities, true)
            || in_array('admin', $abilities, true);

        return $hasRefreshAbility && ! $hasAccessAbility;
    }
}
