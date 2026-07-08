<?php

namespace App\Http\Controllers\v1\Customer\Auth;

use App\Enums\eClientType;
use App\Enums\OtpChannelEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Responser\JsonResponser;
use App\Services\Auth\AuthService;
use App\Support\OtpFlowLogger;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function verify(VerifyOtpRequest $request)
    {
        $challengeToken = (string) $request->input('challenge_token');

        OtpFlowLogger::log('EMAIL_VERIFICATION', 'HTTP verify-otp request', array_merge(
            OtpFlowLogger::tokenMeta($challengeToken),
            OtpFlowLogger::otpMeta((string) $request->input('otp')),
            ['path' => $request->path()],
        ));

        try {
            $client = (string) ($request->validated('client') ?? eClientType::MOBILE->value);
            $payload = $this->authService->verifyCustomerEmailOtp(
                $challengeToken,
                (string) $request->input('otp'),
                $request,
                $client
            );

            if (isset($payload['access_token'])) {
                return OtpFlowLogger::logAndReturn('EMAIL_VERIFICATION', 'HTTP verify-otp response 200 (email verified + tokens)', [
                    'token_fp' => OtpFlowLogger::tokenFingerprint($challengeToken),
                    'has_access_token' => true,
                ], JsonResponser::send(false, 'Email verified.', AuthResource::make($payload), 200));
            }

            return OtpFlowLogger::logAndReturn('EMAIL_VERIFICATION', 'HTTP verify-otp response 200 (login OTP next)', array_merge(
                OtpFlowLogger::tokenMeta((string) ($payload['challenge_token'] ?? '')),
                ['registration_step' => $payload['registration_step'] ?? null],
            ), JsonResponser::send(false, 'Sign-in code sent. Please verify to complete login.', $payload, 200));
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\EmailVerificationController@verify');

            OtpFlowLogger::log('EMAIL_VERIFICATION', 'HTTP verify-otp response ERROR', array_merge(
                OtpFlowLogger::tokenMeta($challengeToken),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                    'payload_keys' => $th instanceof ApiException && is_array($th->payload)
                        ? implode(',', array_keys($th->payload))
                        : null,
                ]
            ));

            return $response;
        }
    }

    public function resend(ResendOtpRequest $request)
    {
        $challengeToken = (string) $request->input('challenge_token');

        OtpFlowLogger::log('EMAIL_VERIFICATION', 'HTTP resend-otp request', array_merge(
            OtpFlowLogger::tokenMeta($challengeToken),
            ['path' => $request->path(), 'otp_channel' => $request->input('otp_channel')],
        ));

        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'), null);
            $payload = $this->authService->resendCustomerEmailVerificationOtp(
                $challengeToken,
                $channel,
                $request
            );

            $message = isset($payload['otp_channel'])
                ? (OtpChannelEnum::tryFrom($payload['otp_channel']) ?? OtpChannelEnum::EMAIL)->deliveryMessage()
                : 'Verification code sent.';

            OtpFlowLogger::log('EMAIL_VERIFICATION', 'HTTP resend-otp response 200', array_merge(
                OtpFlowLogger::tokenMeta((string) ($payload['challenge_token'] ?? '')),
                [
                    'request_token_fp' => OtpFlowLogger::tokenFingerprint($challengeToken),
                    'token_rotated' => OtpFlowLogger::tokenFingerprint($challengeToken)
                        !== OtpFlowLogger::tokenFingerprint((string) ($payload['challenge_token'] ?? '')),
                    'cooldown_active' => $payload['cooldown_active'] ?? null,
                    'expires_in' => $payload['expires_in'] ?? null,
                ]
            ));

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\EmailVerificationController@resend');

            OtpFlowLogger::log('EMAIL_VERIFICATION', 'HTTP resend-otp response ERROR', array_merge(
                OtpFlowLogger::tokenMeta($challengeToken),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }
}
