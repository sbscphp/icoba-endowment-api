<?php

namespace App\Services\Auth;

use App\Enums\eClientType;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Services\Auth\OtpService;
use App\Exceptions\ApiException;

class AuthService
{
    private $userRepository;
    private $otpService;

    public function __construct(
        UserRepositoryInterface $userRepository,
        OtpService $otpService
    ) {
        $this->userRepository = $userRepository;
        $this->otpService = $otpService;
    }

    public function register(array $data)
    {
        $data['password'] = Hash::make($data['password']);
        return $this->userRepository->create($data);
    }

    public function login(string $email, string $password, string $client = eClientType::MOBILE->value): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ApiException('Invalid credentials.', 422);
        }

        return $this->otpService->sendLoginOtp($user);
    }

    public function verifyOtpAndIssueToken(string $challengeToken, string $otp, string $client): array
    {
        $user = $this->otpService->verifyLoginOtp($challengeToken, $otp);

        $user->tokens()->where('name', $client)->delete();

        $abilities = match (true) {
            $user->hasRole('admin') => ['admin'],
            default => ['customer'],
        };

        $token = $user->createToken($client, $abilities)->plainTextToken;

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }

    public function resendOtp(string $challengeToken)
    {
        $payload = $this->otpService->resendLoginOtp($challengeToken);

        return [
            "payload" => $payload
        ];
    }

    public function logout($user): void
    {
        $user->currentAccessToken()->delete();
    }
}
