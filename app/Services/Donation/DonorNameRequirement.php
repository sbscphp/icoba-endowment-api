<?php

namespace App\Services\Donation;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class DonorNameRequirement
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function assertPresent(array $data, ?User $linkedUser = null): void
    {
        if ($this->resolveFromPayload($data, $linkedUser) !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'donor_name' => ['Donor name is required. Provide donor_name, organization_name, or complete your profile.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveFromPayload(array $data, ?User $user = null): ?string
    {
        $donorName = trim((string) ($data['donor_name'] ?? ''));
        if ($donorName !== '') {
            return $donorName;
        }

        $organizationName = trim((string) ($data['organization_name'] ?? ''));
        if ($organizationName !== '') {
            return $organizationName;
        }

        if ($user !== null) {
            return $this->resolveFromUser($user);
        }

        return null;
    }

    public function resolveFromUser(User $user): ?string
    {
        if (filled($user->organization_name)) {
            return trim((string) $user->organization_name);
        }

        $personalName = trim(implode(' ', array_filter([
            (string) ($user->firstname ?? ''),
            (string) ($user->lastname ?? ''),
        ])));

        return $personalName !== '' ? $personalName : null;
    }
}
