<?php

namespace Tests\Unit\Services\Settings;

use App\Models\Admin;
use App\Services\Settings\AccountSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enable_two_factor(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            '2fa' => false,
        ]);

        $service = app(AccountSettingsService::class);

        $result = $service->toggleAdminTwoFactor($admin, true);

        $this->assertTrue($result['2fa']);
        $this->assertTrue($admin->fresh()->{'2fa'});
    }

    public function test_admin_can_disable_two_factor(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            '2fa' => true,
        ]);

        $service = app(AccountSettingsService::class);

        $result = $service->toggleAdminTwoFactor($admin, false);

        $this->assertFalse($result['2fa']);
        $this->assertFalse($admin->fresh()->{'2fa'});
    }
}
