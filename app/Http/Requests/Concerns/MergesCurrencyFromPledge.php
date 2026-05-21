<?php

namespace App\Http\Requests\Concerns;

use App\Models\Pledge;

trait MergesCurrencyFromPledge
{
    protected function prepareForValidation(): void
    {
        $pledgeUuid = $this->input('pledge_uuid');
        if (! is_string($pledgeUuid) || $pledgeUuid === '') {
            return;
        }

        if ($this->filled('currency')) {
            return;
        }

        $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
        if ($pledge !== null) {
            $this->merge(['currency' => $pledge->currency]);
        }
    }
}
