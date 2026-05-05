<?php

namespace App\Http\Controllers\v1\Auth;

use App\Enums\eClientType;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Responser\JsonResponser;
use App\Services\Auth\AuthService;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function verify(VerifyOtpRequest $request)
    {
        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->verifyCustomerEmailOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request,
                $client
            );

            if (isset($payload['access_token'])) {
                return JsonResponser::send(false, 'Email verified.', AuthResource::make($payload), 200);
            }

            return JsonResponser::send(false, 'Sign-in code sent. Please verify to complete login.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'EmailVerificationController@verify');
        }
    }

    public function resend(ResendOtpRequest $request)
    {
        try {
            $payload = $this->authService->resendCustomerEmailVerificationOtp(
                (string) $request->input('challenge_token'),
                $request
            );

            return JsonResponser::send(false, 'Verification code sent.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'EmailVerificationController@resend');
        }
    }
}
