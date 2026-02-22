<?php

namespace App\Http\Controllers\v1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Responser\JsonResponser;
use App\Services\Auth\PasswordResetService;
use Illuminate\Support\Facades\Log;

class PasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $request->validated();

        $this->passwordResetService->sendResetLink($data['email']);

        return JsonResponser::send(false, 'If your email exists, a reset link has been sent.', null, 200);
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $data = $request->validated();

            $this->passwordResetService->resetPassword(
                $data['email'],
                $data['token'],
                $data['password']
            );

            return JsonResponser::send(false, 'Password reset successful.', null, 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            Log::error('PasswordController@resetPassword: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            return JsonResponser::send(true, 'An error occurred. Please try again later.', null, 500, $th);
        }
    }
}
