<?php

namespace Tests\Unit\Services\Settings;

use App\Enums\DonorTypeSlug;
use App\Models\Admin;
use App\Models\DonorType;
use App\Models\User;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Settings\AccountSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Ic0ba!Xk9mP2vQn7wLz4Rt6';

    private const OLD_STRONG_PASSWORD = 'Ic0ba!OldP4ssw0rdZx9Qm2';

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

    public function test_admin_name_change_sends_in_app_notification(): void
    {
        Notification::fake();

        $admin = Admin::query()->create([
            'name' => 'Old Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        app(AccountSettingsService::class)->updateAdminProfile($admin, 'New Admin');

        Notification::assertSentTo(
            $admin,
            GenericDatabaseNotification::class,
            fn (GenericDatabaseNotification $notification): bool => $notification->event === 'profile_name_updated'
                && str_contains($notification->message, 'Old Admin')
                && str_contains($notification->message, 'New Admin'),
        );
    }

    public function test_admin_password_change_sends_in_app_notification(): void
    {
        Notification::fake();

        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make(self::OLD_STRONG_PASSWORD),
        ]);

        app(AccountSettingsService::class)->updatePassword($admin, self::STRONG_PASSWORD);

        Notification::assertSentTo(
            $admin,
            GenericDatabaseNotification::class,
            fn (GenericDatabaseNotification $notification): bool => $notification->event === 'password_changed',
        );
    }

    public function test_customer_profile_update_sends_in_app_notification(): void
    {
        Notification::fake();

        $donorType = DonorType::query()->create([
            'slug' => DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            'label' => 'Friends of ICOBA',
            'description' => 'Friends of ICOBA',
        ]);

        $user = User::factory()->create([
            'donor_type_uuid' => $donorType->uuid,
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ]);

        app(AccountSettingsService::class)->updateCustomerProfile($user, [
            'firstname' => 'Janet',
        ]);

        Notification::assertSentTo(
            $user,
            GenericDatabaseNotification::class,
            fn (GenericDatabaseNotification $notification): bool => $notification->event === 'profile_updated'
                && str_contains($notification->message, 'Jane')
                && str_contains($notification->message, 'Janet'),
        );
    }

    public function test_customer_password_change_sends_in_app_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make(self::OLD_STRONG_PASSWORD),
        ]);

        app(AccountSettingsService::class)->updatePassword($user, self::STRONG_PASSWORD);

        Notification::assertSentTo(
            $user,
            GenericDatabaseNotification::class,
            fn (GenericDatabaseNotification $notification): bool => $notification->event === 'password_changed',
        );
    }
}
