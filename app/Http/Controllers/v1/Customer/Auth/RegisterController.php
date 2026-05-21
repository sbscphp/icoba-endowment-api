<?php

namespace App\Http\Controllers\v1\Customer\Auth;

use App\Enums\OtpChannelEnum;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DonorRegisterRequest;
use App\Responser\JsonResponser;
use App\Services\Auth\AuthService;
use App\Services\DropdownService;

class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly DropdownService $dropdownService,
    ) {}

    /**
     * Donor registration metadata (types, corporate categories, graduation sets, countries).
     */
    public function metadata()
    {
        try {
            return JsonResponser::send(
                false,
                'Registration options retrieved.',
                $this->dropdownService->donorRegistrationMetadata(),
                200
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\RegisterController@metadata');
        }
    }

    public function store(DonorRegisterRequest $request)
    {
        try {
            $payload = $this->authService->registerCustomer(
                $request->validated(),
                $request
            );

            $channel = OtpChannelEnum::tryFrom($payload['otp_channel'] ?? '') ?? OtpChannelEnum::EMAIL;

            return JsonResponser::send(
                false,
                'Please verify your account. '.$channel->deliveryMessage(),
                $payload,
                201
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\RegisterController@store');
        }
    }
}
