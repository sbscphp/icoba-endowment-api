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

class PasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'));
            $payload = $this->passwordResetService->requestReset((string) $request->input('email'), $channel, $request);

            $message = OpaqueMessageHelper::authOpaqueEnabled('forgot_password')
                ? 'If an account matches what you entered, a verification code will be sent.'
                : 'Verification code sent.';

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPassword');
        }
    }

    public function forgotPasswordResend(ResendOtpRequest $request)
    {
        try {
            $channel = OtpChannelEnum::tryFromRequest($request->input('otp_channel'), null);
            $payload = $this->passwordResetService->resendResetOtp((string) $request->input('challenge_token'), $channel, $request);

            return JsonResponser::send(false, 'If the verification session is valid, a new code will be sent.', $payload, 200);
        } catch (ApiException $e) {
            if ($e->status === 429 && $e->payload !== null) {
                return JsonResponser::send(true, $e->getMessage(), $e->payload, 429);
            }

            if (! OpaqueMessageHelper::authOpaqueEnabled('forgot_password')) {
                throw $e;
            }

            return JsonResponser::send(false, 'If the verification session is valid, a new code will be sent.', [
                'challenge_token' => null,
                'expires_in' => null,
            ], 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPasswordResend');
        }
    }

    public function forgotPasswordVerify(VerifyResetOtpRequest $request)
    {
        try {
            $payload = $this->passwordResetService->verifyResetOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request
            );

            return JsonResponser::send(false, 'Code verified. You may now reset your password.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@forgotPasswordVerify');
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $this->passwordResetService->resetPassword(
                (string) $request->input('reset_token'),
                (string) $request->input('password'),
                $request
            );

            return JsonResponser::send(false, 'Password reset successful.', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Auth\PasswordController@resetPassword');
        }
    }
}
