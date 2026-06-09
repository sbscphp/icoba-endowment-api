<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a customer Sanctum access token when present, without requiring authentication.
 */
final class AttemptSanctumAuthentication
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->bearerToken() !== null) {
            $user = Auth::guard('api')->user();

            if ($user instanceof User) {
                $token = $user->currentAccessToken();
                if ($token instanceof PersonalAccessToken && $this->isRefreshToken($token)) {
                    $user = null;
                }
            } else {
                $user = null;
            }

            if ($user instanceof User) {
                $request->setUserResolver(static fn (): User => $user);
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
