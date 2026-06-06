<?php

namespace App\Services\Donation;

use App\Models\User;
use App\Services\GivingIdentity\GivingIdentityLinkerService;

final class GuestDonorHistoryLinkerService
{
    public function __construct(
        private readonly GivingIdentityLinkerService $linker,
    ) {}

    /**
     * Link prior guest donations and pledges to a newly registered user by giving identity.
     *
     * @return array{transactions: int, pledges: int, pledge_transactions: int, recognitions: int}
     */
    public function linkForUser(User $user): array
    {
        return $this->linker->linkForUser($user);
    }
}
