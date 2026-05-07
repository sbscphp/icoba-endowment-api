<?php

namespace App\Http\Controllers\v1\Admin\Auth;

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
            $email = strtolower(trim((string) $request->input('email')));
            $payload = $this->passwordResetService->requestAdminReset($email, $request);

            $message = OpaqueMessageHelper::authOpaqueEnabled('forgot_password')
                ? 'If an account matches what you entered, a verification code will be sent.'
                : 'Verification code sent.';

            return JsonResponser::send(false, $message, $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Auth\PasswordController@forgotPassword');
        }
    }

    public function forgotPasswordResend(ResendOtpRequest $request)
    {
        try {
            $payload = $this->passwordResetService->resendAdminResetOtp((string) $request->input('challenge_token'), $request);

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
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Auth\PasswordController@forgotPasswordResend');
        }
    }

    public function forgotPasswordVerify(VerifyResetOtpRequest $request)
    {
        try {
            $payload = $this->passwordResetService->verifyAdminResetOtp(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                $request
            );

            return JsonResponser::send(false, 'Code verified. You may now reset your password.', $payload, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Auth\PasswordController@forgotPasswordVerify');
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

            return JsonResponser::send(false, 'Password reset successful. You may now log in.', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Auth\PasswordController@resetPassword');
        }
    }
}

