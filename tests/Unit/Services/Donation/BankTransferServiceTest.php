<?php

namespace Tests\Unit\Services\Donation;

use App\Enums\CampaignStatus;
use App\Enums\ePermission;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Donation\BankTransferService;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BankTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_confirm_payment_notifies_admins_with_transaction_and_reconciliation_read_permissions(): void
    {
        config(['app.admin_frontend_url' => 'https://admin.example.com']);

        $transaction = $this->createBankTransferTransaction([
            'amount' => 25000,
            'currency' => 'NGN',
            'bank_transfer_reference' => 'REF-ABC123XYZ',
        ]);

        $dispatch = Mockery::mock(NotificationDispatchService::class);
        $dispatch->shouldReceive('notifyAdminsWithAllPermissions')
            ->once()
            ->withArgs(function (array $permissions, GenericDatabaseNotification $notification) use ($transaction): bool {
                return $permissions === [
                    ePermission::TRANSACTIONS_READ->value,
                    ePermission::RECONCILIATION_READ->value,
                ]
                    && $notification->event === 'bank_transfer_payment_confirmed'
                    && $notification->message === 'A payment of ₦25,000.00 with reference REF-ABC123XYZ has been made. Please reconcile.'
                    && $notification->sendMail === true
                    && $notification->mailSubject === 'New bank transfer payment requires reconciliation'
                    && $notification->actionUrl === 'https://admin.example.com/reconciliation/queue/'.$transaction->uuid;
            })
            ->andReturn(1);

        $this->app->instance(NotificationDispatchService::class, $dispatch);

        $confirmed = app(BankTransferService::class)->confirmPaymentForCustomer($transaction->uuid, null);

        $this->assertNotNull($confirmed->awaiting_bank_verification_at);
    }

    public function test_confirm_payment_does_not_notify_admins_when_already_confirmed(): void
    {
        $confirmedAt = now()->subMinute();
        $transaction = $this->createBankTransferTransaction([
            'awaiting_bank_verification_at' => $confirmedAt,
        ]);

        $dispatch = Mockery::mock(NotificationDispatchService::class);
        $dispatch->shouldNotReceive('notifyAdminsWithAllPermissions');
        $this->app->instance(NotificationDispatchService::class, $dispatch);

        $result = app(BankTransferService::class)->confirmPaymentForCustomer($transaction->uuid, null);

        $this->assertNotNull($result->awaiting_bank_verification_at);
        $this->assertSame(
            $confirmedAt->toIso8601String(),
            $result->awaiting_bank_verification_at->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBankTransferTransaction(array $overrides = []): Transaction
    {
        $campaign = Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 1000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => CampaignStatus::ACTIVE->value,
        ]);

        return Transaction::query()->create(array_merge([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $campaign->uuid,
            'amount' => 1000,
            'currency' => 'NGN',
            'status' => TransactionStatus::PENDING->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'bank_transfer_reference' => 'REF-TEST123',
        ], $overrides));
    }
}
