<?php

namespace App\Http\Controllers\v1\Customer\Auth;

use App\Enums\CustomerRegistrationStepEnum;
use App\Enums\eClientType;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Http\Resources\Auth\TokenRefreshResource;
use App\Responser\JsonResponser;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(LoginRequest $request)
    {
        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->loginCustomer(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request,
                $client
            );

            if (isset($payload['access_token'])) {
                return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
            }

            $message = ($payload['registration_step'] ?? '') === CustomerRegistrationStepEnum::AWAITING_OTP->value
                ? 'Please verify your email. A verification code has been sent.'
                : 'Verification code sent.';

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@login');
        }
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->verifyCustomerLoginOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request,
                $client
            );

            return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@verifyOtp');
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $payload = $this->authService->resendCustomerLoginOtp((string) $request->input('challenge_token'), $request);

            return JsonResponser::send(false, 'Verification code sent.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@resendOtp');
        }
    }

    public function refresh(RefreshTokenRequest $request)
    {
        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->refreshCustomerToken(
                (string) $request->input('refresh_token'),
                $request,
                $client
            );

            return JsonResponser::send(false, 'Token refreshed successfully.', TokenRefreshResource::make($payload), 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@refresh');
        }
    }

    public function logout(Request $request)
    {
        try {
            $this->authService->logout($request->user());

            return JsonResponser::send(false, 'Logged out successfully!', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@logout');
        }
    }
}
