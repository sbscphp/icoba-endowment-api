<?php

namespace App\Http\Controllers\v1\Admin\Auth;

use App\Enums\eClientType;
use App\Enums\OtpChannelEnum;
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

class AdminLoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request)
    {
        try {
            $payload = $this->authService->loginAdmin(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request,
                eClientType::WEB->value
            );

            if (isset($payload['access_token'])) {
                return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
            }

            return JsonResponser::send(false, 'Verification code sent.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AdminLoginController@login');
        }
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        try {
            $payload = $this->authService->verifyAdminLoginOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request,
                eClientType::WEB->value
            );

            return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AdminLoginController@verifyOtp');
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'), null);
            $payload = $this->authService->resendAdminLoginOtp(
                (string) $request->input('challenge_token'),
                $channel,
                $request
            );

            $message = isset($payload['otp_channel'])
                ? (OtpChannelEnum::tryFrom($payload['otp_channel']) ?? OtpChannelEnum::EMAIL)->deliveryMessage()
                : 'Verification code sent.';

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AdminLoginController@resendOtp');
        }
    }

    public function refresh(RefreshTokenRequest $request)
    {
        try {
            $payload = $this->authService->refreshAdminToken(
                (string) $request->input('refresh_token'),
                $request,
                eClientType::WEB->value
            );

            return JsonResponser::send(false, 'Token refreshed successfully.', TokenRefreshResource::make($payload), 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AdminLoginController@refresh');
        }
    }

    public function logout(Request $request)
    {
        try {
            $this->authService->logout($request->user());

            return JsonResponser::send(false, 'Logged out successfully!', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AdminLoginController@logout');
        }
    }
}
