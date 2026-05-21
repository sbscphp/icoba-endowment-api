<?php

namespace App\Http\Controllers\Developer;

use App\Enums\Api\ApiEncryptionMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Developer\RegisterApiUserRequest;
use App\Responser\JsonResponser;
use App\Services\Api\ApiUserProvisioningService;

final class ApiUserRegistrationController extends Controller
{
    private const DEV_SECRET_HEADER = 'X-Dev-Api-User-Secret';

    public function store(
        RegisterApiUserRequest $request,
        ApiUserProvisioningService $provisioning,
    ) {
        if (! config('security.api_user_dev_registration.enabled')) {
            abort(403, 'Disabled.');
        }

        $expected = (string) config('security.api_user_dev_registration.secret');
        if ($expected === '') {
            abort(503, 'Registration secret is not configured.');
        }

        $provided = (string) $request->header(self::DEV_SECRET_HEADER, '');
        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid registration secret.');
        }

        $validated = $request->validated();
        $requestedMode = $validated['encryption_mode'] ?? null;

        $mode = match (true) {
            $requestedMode instanceof ApiEncryptionMode => $requestedMode,
            is_string($requestedMode) && $requestedMode !== '' => ApiEncryptionMode::from($requestedMode),
            default => ApiEncryptionMode::tryFromConfig(config('security.api_encryption.default_mode')) ?? ApiEncryptionMode::Both,
        };

        $user = $provisioning->createAndNotify(
            email: (string) $request->validated('email'),
            name: $request->validated('name'),
            mode: $mode,
        );

        return JsonResponser::send(
            error: false,
            message: 'API user created. Credentials were sent by email.',
            data: [
                'email' => $user->email,
                'client_key' => $user->client_key,
                'encryption_mode' => $user->encryption_mode->value,
            ],
            statusCode: 201,
        );
    }
}
