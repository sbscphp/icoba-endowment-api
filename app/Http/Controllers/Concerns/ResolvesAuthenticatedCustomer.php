<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesAuthenticatedCustomer
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function filtersWithCustomerAuthContext(array $validated, Request $request): array
    {
        $validated['for_authenticated_customer'] = $this->isAuthenticatedCustomer($request);

        return $validated;
    }

    protected function isAuthenticatedCustomer(Request $request): bool
    {
        $user = $request->user() ?? Auth::guard('api')->user();

        if (! $user instanceof User) {
            return false;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        if (str_ends_with((string) $token->name, ':refresh')) {
            return false;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $hasRefreshAbility = in_array('customer:refresh', $abilities, true);
        $hasAccessAbility = in_array('customer', $abilities, true);

        return ! ($hasRefreshAbility && ! $hasAccessAbility);
    }
}
