<?php

namespace App\Repositories\Contracts\User;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    /**
     * Create a new user
     */
    public function create(array $data): User;

    /**
     * Find user by ID
     */
    public function findById(int $id): ?User;

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?User;

    /**
     * Update user
     */
    public function update(User $user, array $data): User;

    /**
     * Delete user
     */
    public function delete(User $user): bool;

    /**
     * Get all users (admin usage)
     */
    public function all(): Collection;
}
