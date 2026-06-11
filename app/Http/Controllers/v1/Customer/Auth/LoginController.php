<?php

namespace App\Http\Controllers\v1\Customer\Auth;

use App\Enums\CustomerRegistrationStepEnum;
use App\Enums\eClientType;
use App\Enums\OtpChannelEnum;
use App\Exceptions\ApiException;
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
use App\Support\OtpFlowLogger;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    private const FLOW = 'LOGIN';

    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(LoginRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP login request', OtpFlowLogger::requestMeta($request));

        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->loginCustomer(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request,
                $client
            );

            if (isset($payload['access_token'])) {
                return OtpFlowLogger::logAndReturn(self::FLOW, 'HTTP login response 200 (direct login)', OtpFlowLogger::authPayloadMeta($payload),
                    JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200));
            }

            $message = ($payload['registration_step'] ?? '') === CustomerRegistrationStepEnum::AWAITING_OTP->value
                ? 'Please verify your email. A verification code has been sent.'
                : 'Verification code sent.';

            OtpFlowLogger::log(self::FLOW, 'HTTP login response 200 (OTP required)', OtpFlowLogger::authPayloadMeta($payload));

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@login');

            OtpFlowLogger::log(self::FLOW, 'HTTP login response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP verify-otp request', OtpFlowLogger::requestMeta($request));

        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->verifyCustomerLoginOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request,
                $client
            );

            return OtpFlowLogger::logAndReturn(self::FLOW, 'HTTP verify-otp response 200', OtpFlowLogger::authPayloadMeta($payload),
                JsonResponser::send(false, 'Login successful.', AuthResource::make($payload), 200));
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@verifyOtp');

            OtpFlowLogger::log(self::FLOW, 'HTTP verify-otp response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP resend-otp request', OtpFlowLogger::requestMeta($request));

        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'), null);
            $payload = $this->authService->resendCustomerLoginOtp(
                (string) $request->input('challenge_token'),
                $channel,
                $request
            );

            $message = isset($payload['otp_channel'])
                ? (OtpChannelEnum::tryFrom($payload['otp_channel']) ?? OtpChannelEnum::EMAIL)->deliveryMessage()
                : 'Verification code sent.';

            OtpFlowLogger::log(self::FLOW, 'HTTP resend-otp response 200', array_merge(
                OtpFlowLogger::authPayloadMeta($payload),
                [
                    'request_token_fp' => OtpFlowLogger::tokenFingerprint((string) $request->input('challenge_token')),
                    'token_rotated' => OtpFlowLogger::tokenFingerprint((string) $request->input('challenge_token'))
                        !== OtpFlowLogger::tokenFingerprint((string) ($payload['challenge_token'] ?? '')),
                ]
            ));

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\LoginController@resendOtp');

            OtpFlowLogger::log(self::FLOW, 'HTTP resend-otp response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
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
