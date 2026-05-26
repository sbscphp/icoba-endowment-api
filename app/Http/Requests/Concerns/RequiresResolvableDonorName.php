<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Services\Donation\DonorNameRequirement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\App;

trait RequiresResolvableDonorName
{
    protected function appendRequiresResolvableDonorNameValidation(Validator $validator, bool $isMember): void
    {
        $validator->after(function (Validator $validator) use ($isMember): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $isMember) {
                return;
            }

            if ($this->hasResolvableDonorNameForMember()) {
                return;
            }

            $validator->errors()->add(
                'donor_name',
                'Donor name is required. Provide donor_name, organization_name, or complete your profile.',
            );
        });
    }

    protected function hasResolvableDonorNameForMember(): bool
    {
        $user = $this->user();
        $user = $user instanceof User ? $user : null;

        return $this->donorNameRequirement()->resolveFromPayload($this->all(), $user) !== null;
    }

    protected function donorNameRequirement(): DonorNameRequirement
    {
        return App::make(DonorNameRequirement::class);
    }
}
