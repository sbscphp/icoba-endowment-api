<?php

namespace Tests\Unit\Services\Notifications;

use App\Enums\ePermission;
use App\Models\Admin;
use App\Models\Permission;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDispatchServicePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_notify_admins_with_all_permissions_sends_to_matching_admins_only(): void
    {
        Notification::fake();

        $eligibleAdmin = Admin::query()->create([
            'name' => 'Reconciliation Admin',
            'email' => 'recon@example.com',
            'password' => 'password',
        ]);
        $eligibleAdmin->givePermissionTo([
            ePermission::TRANSACTIONS_READ->value,
            ePermission::RECONCILIATION_READ->value,
        ]);

        $partialAdmin = Admin::query()->create([
            'name' => 'Transactions Only Admin',
            'email' => 'transactions@example.com',
            'password' => 'password',
        ]);
        $partialAdmin->givePermissionTo(ePermission::TRANSACTIONS_READ->value);

        $notification = new GenericDatabaseNotification(
            module: 'reconciliation',
            event: 'bank_transfer_payment_confirmed',
            title: 'New bank transfer payment',
            message: 'Test message',
        );

        app(NotificationDispatchService::class)->notifyAdminsWithAllPermissions(
            [
                ePermission::TRANSACTIONS_READ->value,
                ePermission::RECONCILIATION_READ->value,
            ],
            $notification,
        );

        Notification::assertSentTo($eligibleAdmin, GenericDatabaseNotification::class);
        Notification::assertNotSentTo($partialAdmin, GenericDatabaseNotification::class);
    }

    public function test_notify_admins_with_all_permissions_sends_email_when_enabled(): void
    {
        Notification::fake();

        $admin = Admin::query()->create([
            'name' => 'Reconciliation Admin',
            'email' => 'recon@example.com',
            'password' => 'password',
            'email_notifications_enabled' => true,
        ]);
        $admin->givePermissionTo([
            ePermission::TRANSACTIONS_READ->value,
            ePermission::RECONCILIATION_READ->value,
        ]);

        app(NotificationDispatchService::class)->notifyAdminsWithAllPermissions(
            [
                ePermission::TRANSACTIONS_READ->value,
                ePermission::RECONCILIATION_READ->value,
            ],
            new GenericDatabaseNotification(
                module: 'reconciliation',
                event: 'bank_transfer_payment_confirmed',
                title: 'New bank transfer payment',
                message: 'A payment of ₦25,000.00 with reference REF-ABC123XYZ has been made. Please reconcile.',
                mailSubject: 'New bank transfer payment requires reconciliation',
                sendMail: true,
            ),
        );

        Notification::assertSentTo(
            $admin,
            GenericDatabaseNotification::class,
            function (GenericDatabaseNotification $notification, array $channels): bool {
                return in_array('mail', $channels, true)
                    && in_array('database', $channels, true);
            },
        );
    }

    public function test_notify_admins_with_all_permissions_returns_zero_when_no_admins_match(): void
    {
        Notification::fake();

        $count = app(NotificationDispatchService::class)->notifyAdminsWithAllPermissions(
            [Permission::query()->value('name') ?? ePermission::TRANSACTIONS_READ->value],
            new GenericDatabaseNotification(
                module: 'reconciliation',
                event: 'bank_transfer_payment_confirmed',
                title: 'New bank transfer payment',
                message: 'Test message',
            ),
        );

        $this->assertSame(0, $count);
        Notification::assertNothingSent();
    }
}
