<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackLastActiveAt
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user instanceof User && ! $user instanceof Admin) {
            return $response;
        }

        $interval = max(1, (int) config('security.last_active_update_interval_seconds', 300));
        $key = 'last_active_at:'.get_class($user).':'.$user->getKey();

        if (! Cache::add($key, true, $interval)) {
            return $response;
        }

        // Middleware is used because activity is a cross-cutting concern for every
        // authenticated API request, not business logic owned by individual controllers.
        $user->forceFill(['last_active_at' => now()])->saveQuietly();

        return $response;
    }
}
