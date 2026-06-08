<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\eClientType;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = app(AuthService::class);
    }

    public function test_customer_can_refresh_access_token_with_valid_refresh_token(): void
    {
        $user = User::factory()->create();
        $refresh = $user->createToken('mobile:refresh', ['customer:refresh'], now()->addDays(30));
        $user->createToken('mobile', ['customer'], now()->addMinutes(60));

        $payload = $this->authService->refreshCustomerToken(
            $refresh->plainTextToken,
            Request::create('/api/v1/auth/refresh', 'POST'),
            eClientType::MOBILE->value
        );

        $this->assertArrayHasKey('access_token', $payload);
        $this->assertArrayHasKey('refresh_token', $payload);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertGreaterThan(0, $payload['expires_in']);
        $this->assertGreaterThan(0, $payload['refresh_expires_in']);
        $this->assertNotSame($refresh->plainTextToken, $payload['refresh_token']);
    }

    public function test_admin_can_refresh_access_token_with_valid_refresh_token(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin-refresh@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
        ]);

        $refresh = $admin->createToken('web:refresh', ['admin:refresh'], now()->addDays(30));
        $admin->createToken('web', ['admin'], now()->addMinutes(60));

        $payload = $this->authService->refreshAdminToken(
            $refresh->plainTextToken,
            Request::create('/api/v1/admin/auth/refresh', 'POST'),
            eClientType::WEB->value
        );

        $this->assertArrayHasKey('access_token', $payload);
        $this->assertArrayHasKey('refresh_token', $payload);
        $this->assertNotSame($refresh->plainTextToken, $payload['refresh_token']);
    }

    public function test_refresh_rejects_expired_refresh_token(): void
    {
        $user = User::factory()->create();
        $refresh = $user->createToken('mobile:refresh', ['customer:refresh'], now()->subMinute());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token.');

        $this->authService->refreshCustomerToken(
            $refresh->plainTextToken,
            Request::create('/api/v1/auth/refresh', 'POST'),
            eClientType::MOBILE->value
        );
    }

    public function test_refresh_rejects_access_token_presented_as_refresh_token(): void
    {
        $user = User::factory()->create();
        $access = $user->createToken('mobile', ['customer'], now()->addMinutes(60));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token.');

        $this->authService->refreshCustomerToken(
            $access->plainTextToken,
            Request::create('/api/v1/auth/refresh', 'POST'),
            eClientType::MOBILE->value
        );
    }

    public function test_logout_revokes_access_and_refresh_tokens_for_client(): void
    {
        $user = User::factory()->create();
        $access = $user->createToken('mobile', ['customer'], now()->addMinutes(60));
        $user->createToken('mobile:refresh', ['customer:refresh'], now()->addDays(30));

        $authenticated = $user->withAccessToken(PersonalAccessToken::findToken($access->plainTextToken));
        $this->authService->logout($authenticated);

        $this->assertSame(0, $user->tokens()->count());
    }
}
