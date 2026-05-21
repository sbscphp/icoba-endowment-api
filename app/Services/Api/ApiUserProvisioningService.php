<?php

namespace App\Services\Api;

use App\Enums\Api\ApiEncryptionMode;
use App\Mail\ApiUserCredentialsMail;
use App\Models\ApiUser;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Repositories\Contracts\Theme\ThemeRepositoryInterface;
use App\Services\CryptoService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ApiUserProvisioningService
{
    public function __construct(
        private readonly ApiUserRepositoryInterface $apiUsers,
        private readonly ThemeRepositoryInterface $themes,
    ) {}

    public function createAndNotify(string $email, ?string $name, ApiEncryptionMode $mode): ApiUser
    {
        [$encryptionKey, $iv] = CryptoService::generateKeyMaterial();

        $user = $this->apiUsers->create([
            'name' => $name,
            'email' => $email,
            'client_key' => (string) Str::uuid(),
            'encryption_key' => $encryptionKey,
            'iv' => $iv,
            'encryption_mode' => $mode,
            'is_active' => true,
        ]);

        Mail::to($email)->send(new ApiUserCredentialsMail($user, $this->themes->getDefault()));

        return $user;
    }
}
