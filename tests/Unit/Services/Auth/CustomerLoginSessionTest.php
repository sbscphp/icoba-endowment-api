<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\eClientType;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerLoginSessionTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = app(AuthService::class);
    }

    public function test_customer_login_revokes_existing_tokens_when_invalidation_enabled(): void
    {
        config(['security.customer_invalidate_tokens_on_login' => true]);

        $user = $this->createLoginableCustomer();
        $request = Request::create('/api/v1/auth/login', 'POST');

        $this->authService->loginCustomer($user->email, 'password', $request, eClientType::MOBILE->value);
        $this->assertSame(2, $user->tokens()->count());

        $this->authService->loginCustomer($user->email, 'password', $request, eClientType::MOBILE->value);
        $this->assertSame(2, $user->fresh()->tokens()->count());
    }

    public function test_customer_login_keeps_existing_tokens_when_invalidation_disabled(): void
    {
        config(['security.customer_invalidate_tokens_on_login' => false]);

        $user = $this->createLoginableCustomer();
        $request = Request::create('/api/v1/auth/login', 'POST');

        $this->authService->loginCustomer($user->email, 'password', $request, eClientType::MOBILE->value);
        $this->assertSame(2, $user->tokens()->count());

        $this->authService->loginCustomer($user->email, 'password', $request, eClientType::MOBILE->value);
        $this->assertSame(4, $user->fresh()->tokens()->count());
    }

    private function createLoginableCustomer(): User
    {
        return User::factory()->create([
            'email' => 'customer-session@example.com',
            'password' => Hash::make('password'),
            '2fa' => false,
            'email_verified_at' => now(),
        ]);
    }
}
