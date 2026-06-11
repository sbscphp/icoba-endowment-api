<?php

namespace App\Http\Controllers\v1\Customer\Auth;

use App\Enums\OtpChannelEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Helpers\OpaqueMessageHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyResetOtpRequest;
use App\Responser\JsonResponser;
use App\Services\Auth\PasswordResetService;
use App\Support\OtpFlowLogger;

class PasswordController extends Controller
{
    private const FLOW = 'PASSWORD_RESET';

    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password request', OtpFlowLogger::requestMeta($request));

        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'));
            $payload = $this->passwordResetService->requestReset((string) $request->input('email'), $channel, $request);

            $message = OpaqueMessageHelper::authOpaqueEnabled('forgot_password')
                ? 'If an account matches what you entered, a verification code will be sent.'
                : 'Verification code sent.';

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password response 200', OtpFlowLogger::authPayloadMeta($payload));

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPassword');

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }

    public function forgotPasswordResend(ResendOtpRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password resend request', OtpFlowLogger::requestMeta($request));

        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'), null);
            $payload = $this->passwordResetService->resendResetOtp((string) $request->input('challenge_token'), $channel, $request);

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password resend response 200', array_merge(
                OtpFlowLogger::authPayloadMeta($payload),
                [
                    'request_token_fp' => OtpFlowLogger::tokenFingerprint((string) $request->input('challenge_token')),
                    'token_rotated' => OtpFlowLogger::tokenFingerprint((string) $request->input('challenge_token'))
                        !== OtpFlowLogger::tokenFingerprint((string) ($payload['challenge_token'] ?? '')),
                ]
            ));

            return JsonResponser::send(false, 'If the verification session is valid, a new code will be sent.', $payload, 200);
        } catch (ApiException $e) {
            if ($e->status === 429 && $e->payload !== null) {
                OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password resend response 429', array_merge(
                    OtpFlowLogger::requestMeta($request),
                    OtpFlowLogger::authPayloadMeta($e->payload),
                    ['message' => $e->getMessage()]
                ));

                return JsonResponser::send(true, $e->getMessage(), $e->payload, 429);
            }

            if (! OpaqueMessageHelper::authOpaqueEnabled('forgot_password')) {
                throw $e;
            }

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password resend response 200 (opaque)', OtpFlowLogger::requestMeta($request));

            return JsonResponser::send(false, 'If the verification session is valid, a new code will be sent.', [
                'challenge_token' => null,
                'expires_in' => null,
            ], 200);
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPasswordResend');

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password resend response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }

    public function forgotPasswordVerify(VerifyResetOtpRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password verify request', OtpFlowLogger::requestMeta($request));

        try {
            $payload = $this->passwordResetService->verifyResetOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request
            );

            return OtpFlowLogger::logAndReturn(self::FLOW, 'HTTP forgot-password verify response 200', OtpFlowLogger::authPayloadMeta($payload),
                JsonResponser::send(false, 'Code verified. You may now reset your password.', $payload, 200));
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPasswordVerify');

            OtpFlowLogger::log(self::FLOW, 'HTTP forgot-password verify response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        OtpFlowLogger::log(self::FLOW, 'HTTP reset-password request', OtpFlowLogger::requestMeta($request));

        try {
            $this->passwordResetService->resetPassword(
                (string) $request->input('reset_token'),
                (string) $request->input('password'),
                $request
            );

            return OtpFlowLogger::logAndReturn(self::FLOW, 'HTTP reset-password response 200', [],
                JsonResponser::send(false, 'Password reset successful.', null, 200));
        } catch (\Throwable $th) {
            $response = GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@resetPassword');

            OtpFlowLogger::log(self::FLOW, 'HTTP reset-password response ERROR', array_merge(
                OtpFlowLogger::requestMeta($request),
                [
                    'status' => $response->getStatusCode(),
                    'message' => $th instanceof ApiException ? $th->getMessage() : $th->getMessage(),
                ]
            ));

            return $response;
        }
    }
}
