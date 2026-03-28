<?php

namespace App\Http\Controllers\v1\Auth;

use App\Enums\eClientType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\LoginPayloadResource;
use App\Http\Resources\Auth\ResendOtpPayloadResource;
use App\Responser\JsonResponser;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function store(LoginRequest $request)
    {
        try {
            $validated = $request->validated();

            $token = $this->authService->login($validated['email'], $validated['password'], $validated['client'] ?? eClientType::MOBILE->value);

            return JsonResponser::send(false, 'OTP sent to your email. Please verify to complete login.', $token, 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            Log::error('LoginController@store: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            return JsonResponser::send(true, 'An error occurred. Please try again later.', null, 500, $th);
        }
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        try {
            $payload = $this->authService->verifyOtpAndIssueToken(
                $request->challenge_token,
                $request->otp,
                $request->client
            );

            return JsonResponser::send(false, 'Login successful.', LoginPayloadResource::make($payload), 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            Log::error('LoginController@verifyOtp: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            return JsonResponser::send(true, 'An error occurred. Please try again later.', null, 500, $th);
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $payload = $this->authService->resendOtp($request->challenge_token);
            Log::info('LoginController@resendOtp: ' . json_encode($payload));
            return JsonResponser::send(false, 'OTP resent successfully.', ResendOtpPayloadResource::make($payload), 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            Log::error('LoginController@resendOtp: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            return JsonResponser::send(true, 'An error occurred. Please try again later.', null, 500, $th);
        }
    }

    public function logout(Request $request)
    {
        try {
            $this->authService->logout($request->user());
            return JsonResponser::send(false, 'Logged out successfully!', null, 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            Log::error('LoginController@logout: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            return JsonResponser::send(true, 'An error occurred. Please try again later.', null, 500, $th);
        }
    }
}
