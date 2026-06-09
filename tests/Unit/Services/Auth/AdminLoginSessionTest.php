<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\eClientType;
use App\Models\Admin;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminLoginSessionTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = app(AuthService::class);
    }

    public function test_admin_login_revokes_existing_tokens_when_invalidation_enabled(): void
    {
        config(['security.admin_invalidate_tokens_on_login' => true]);

        $admin = $this->createLoginableAdmin();
        $request = Request::create('/api/v1/admin/auth/login', 'POST');

        $this->authService->loginAdmin($admin->email, 'password', $request, eClientType::WEB->value);
        $this->assertSame(2, $admin->tokens()->count());

        $this->authService->loginAdmin($admin->email, 'password', $request, eClientType::WEB->value);
        $this->assertSame(2, $admin->fresh()->tokens()->count());
    }

    public function test_admin_login_keeps_existing_tokens_when_invalidation_disabled(): void
    {
        config(['security.admin_invalidate_tokens_on_login' => false]);

        $admin = $this->createLoginableAdmin();
        $request = Request::create('/api/v1/admin/auth/login', 'POST');

        $this->authService->loginAdmin($admin->email, 'password', $request, eClientType::WEB->value);
        $this->assertSame(2, $admin->tokens()->count());

        $this->authService->loginAdmin($admin->email, 'password', $request, eClientType::WEB->value);
        $this->assertSame(4, $admin->fresh()->tokens()->count());
    }

    private function createLoginableAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Session Test Admin',
            'email' => 'admin-session@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);
    }
}
