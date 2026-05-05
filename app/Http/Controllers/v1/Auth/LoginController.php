<?php

namespace App\Http\Controllers\v1\Auth;

use App\Enums\eClientType;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
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
            $payload = $this->authService->loginCustomer(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request,
                eClientType::MOBILE->value
            );

            if (isset($payload['access_token'])) {
                return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
            }

            return JsonResponser::send(false, 'Verification code sent.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'LoginController@login');
        }
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        try {
            $payload = $this->authService->verifyCustomerLoginOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request,
                eClientType::MOBILE->value
            );

            return JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'LoginController@verifyOtp');
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $payload = $this->authService->resendCustomerLoginOtp((string) $request->input('challenge_token'), $request);

            return JsonResponser::send(false, 'Verification code sent.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'LoginController@resendOtp');
        }
    }

    public function logout(Request $request)
    {
        try {
            $this->authService->logout($request->user());

            return JsonResponser::send(false, 'Logged out successfully!', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'LoginController@logout');
        }
    }
}
