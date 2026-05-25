<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Services\Recognition\DonorRecognitionService;

final class CustomerRecognitionService
{
    public function __construct(
        private readonly DonorRecognitionService $recognitionService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\DonorRecognition>
     */
    public function listForUser(User $user, array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->recognitionService->listForUser($user, $filters);
    }
}
