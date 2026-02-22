<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use App\Exceptions\ApiException;
use App\Mail\LoginOtpMail;
use App\Models\OneTimePassword;
use App\Repositories\Contracts\Auth\OtpRepositoryInterface;
use App\Services\Theme\ThemeResolver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private const PURPOSE_LOGIN = 'login';
    private const OTP_EXP_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const RESEND_WINDOW_MINUTES = 10;
    private const MAX_RESENDS_IN_WINDOW = 3;


    public function __construct(private OtpRepositoryInterface $otpService) {}

    public function sendLoginOtp(User $user): array
    {
        $this->otpService->invalidateAll($user->id, 'login');

        $otp = (string) random_int(100000, 999999);

        $oneTimePassword = $this->otpService->create([
            'user_id' => $user->id,
            'purpose' => 'login',
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
        ]);

        $theme = app(ThemeResolver::class)->resolveForMail();

        Mail::to("damilolasbsc@yopmail.com")->send(new LoginOtpMail($otp, 10, $theme));

        // TODO SMS: integrate provider (Termii, Twilio, etc.)
        // $this->sms->send($user->phone, "Your OTP is {$otp}");

        $challengeToken = encrypt([
            'uid' => $user->id,
            'uuid' => $oneTimePassword->uuid,
            'purpose' => 'login',
            'exp' => now()->addMinutes(10)->timestamp,
        ]);

        return [
            'challenge_token' => $challengeToken,
            'expires_in' => 600,
        ];
    }

    public function verifyLoginOtp(string $challengeToken, string $otpCode): User
    {
        $payload = decrypt($challengeToken);

        if (($payload['purpose'] ?? null) !== 'login') {
            throw new ApiException('Invalid challenge token.', 422);
        }

        if (now()->timestamp > (int) ($payload['exp'] ?? 0)) {
            throw new ApiException('OTP token has expired.', 422);
        }

        $uuid = $payload['uuid'] ?? null;
        $userId = $payload['uid'] ?? null;

        if (! $uuid || ! $userId) {
            throw new ApiException('Malformed challenge token.', 422);
        }

        $otp = $this->otpService->findByUuid($uuid);

        if (! $otp || now()->greaterThan($otp->expires_at) || $otp->used_at) {
            throw new ApiException('OTP expired or not found.', 422);
        }

        if ($otp->attempts >= 5) {
            throw new ApiException('Too many attempts. Request a new OTP.', 422);
        }

        $this->otpService->incrementAttempts($otp->id);

        if (! Hash::check($otpCode, $otp->code_hash)) {
            throw new ApiException('Invalid OTP.', 422);
        }

        $this->otpService->markUsed($otp->id);

        return User::findOrFail($userId);
    }

    public function resendLoginOtp(string $challengeToken): array
    {
        $payload = decrypt($challengeToken);

        if (($payload['purpose'] ?? null) !== self::PURPOSE_LOGIN) {
            throw new ApiException('Invalid challenge token.', 422);
        }

        if (now()->timestamp > (int) ($payload['exp'] ?? 0)) {
            throw new ApiException('OTP challenge expired. Please login again.', 422);
        }

        $uuid = $payload['uuid'] ?? null;
        $userId = $payload['uid'] ?? null;

        if (! $uuid || ! $userId) {
            throw new ApiException('Malformed challenge token.', 422);
        }

        $otp = $this->otpService->findByUuid($uuid);

        if (! $otp || $otp->used_at || now()->greaterThan($otp->expires_at)) {
            throw new ApiException('OTP expired or already used. Please login again.', 422);
        }

        $secondsSinceLastSend = now()->diffInSeconds($otp->created_at);
        if ($secondsSinceLastSend < self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - $secondsSinceLastSend;
            throw new ApiException("Please wait {$wait} seconds before requesting a new OTP.", 422);
        }

        $count = $this->otpService->countRecentResends($userId, self::PURPOSE_LOGIN, self::RESEND_WINDOW_MINUTES);
        if ($count >= self::MAX_RESENDS_IN_WINDOW) {
            throw new ApiException('Too many OTP requests. Please try again later.', 429);
        }

        $this->otpService->invalidateById($otp->id);

        $newOtpCode = (string) random_int(100000, 999999);

        $newOtp = $this->otpService->create([
            'user_id' => $userId,
            'purpose' => self::PURPOSE_LOGIN,
            'code_hash' => Hash::make($newOtpCode),
            'expires_at' => now()->addMinutes(self::OTP_EXP_MINUTES),
        ]);

        $user = User::findOrFail($userId);
        $theme = app(ThemeResolver::class)->resolveForMail();

        // Log::info('OtpService@resendLoginOtp: OTP resent', [
        //     'email' => $user->email,
        //     'otp' => $newOtpCode,
        // ]);

        Mail::to($user->email)->send(new LoginOtpMail($newOtpCode, 10, $theme));
        // $user->notify(new \App\Notifications\LoginOtpNotification($newOtpCode));
        // SMS provider call here too
     
        $newToken = encrypt([
            'uuid' => $newOtp->uuid,
            'uid' => $userId,
            'purpose' => self::PURPOSE_LOGIN,
            'exp' => now()->addMinutes(self::OTP_EXP_MINUTES)->timestamp,
        ]);

        return [
            'challenge_token' => $newToken,
            'expires_in' => self::OTP_EXP_MINUTES * 60,
        ];
    }
}
