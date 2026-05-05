<?php

namespace Database\Seeders;

use App\Enums\Api\ApiEncryptionMode;
use App\Models\ApiUser;
use App\Services\CryptoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ApiUserSeeder extends Seeder
{
    // php artisan db:seed --class=ApiUserSeeder
    public function run(): void
    {
        [$encryptionKey, $iv] = CryptoService::generateKeyMaterial();

        $mode = ApiEncryptionMode::tryFromConfig(config('security.api_encryption.default_mode'))
            ?? ApiEncryptionMode::Both;

        ApiUser::query()->updateOrCreate(
            ['email' => (string) config('security.api_user_seeder.email')],
            [
                'name' => 'Seeded API client',
                'client_key' => (string) Str::uuid(),
                'encryption_key' => $encryptionKey,
                'iv' => $iv,
                'encryption_mode' => $mode,
                'is_active' => true,
            ],
        );
    }
}
