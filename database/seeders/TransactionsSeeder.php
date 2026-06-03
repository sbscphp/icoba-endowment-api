<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Generates a mix of transactions across all existing campaigns and users.
 *
 *   php artisan db:seed --class=TransactionsSeeder
 */
class TransactionsSeeder extends Seeder
{
    private const COUNT = 10;

    private const GATEWAYS = ['Stripe', 'Paystack', 'Flutterwave', 'Manual'];

    private const PAYMENT_METHODS = ['Card', 'Bank Transfer', 'USSD', 'Wallet'];

    public function run(): void
    {
        $campaigns = Campaign::query()->get(['uuid']);
        $users = User::query()->get(['uuid', 'firstname', 'lastname', 'email', 'phone_number']);

        if ($campaigns->isEmpty()) {
            $this->command?->warn('No campaigns found - create at least one campaign before running this seeder.');

            return;
        }

        for ($i = 0; $i < self::COUNT; $i++) {
            $campaign = $campaigns->random();
            $useExistingUser = $users->isNotEmpty() && random_int(0, 99) < 70;
            $isAnonymous = ! $useExistingUser && random_int(0, 99) < 25;

            $currencyEnum = collect(Currency::cases())->random();
            $currency = $currencyEnum->value;
            $rate = $currencyEnum->referenceNairaRatePerUnit();
            $amount = (float) random_int(500, 250_000) / 100; // 5.00 .. 2,500.00
            if ($currency === 'NGN') {
                $amount = (float) random_int(500_000, 50_000_000) / 100; // 5,000 .. 500,000 NGN
            }
            $amountInNaira = round($amount * $rate, 2);

            $status = collect([
                TransactionStatus::SUCCESSFUL,
                TransactionStatus::SUCCESSFUL,
                TransactionStatus::SUCCESSFUL,
                TransactionStatus::PENDING,
                TransactionStatus::FAILED,
                TransactionStatus::REVERSED,
            ])->random();

            $gateway = self::GATEWAYS[array_rand(self::GATEWAYS)];
            $paymentMethod = self::PAYMENT_METHODS[array_rand(self::PAYMENT_METHODS)];

            $createdAt = now()->subDays(random_int(0, 120))->subMinutes(random_int(0, 1440));
            $paidAt = $status === TransactionStatus::SUCCESSFUL ? $createdAt->copy()->addMinutes(random_int(0, 90)) : null;

            $donorName = null;
            $donorEmail = null;
            $donorPhone = null;
            $userUuid = null;

            if ($isAnonymous) {
                $donorName = 'Anonymous';
            } elseif ($useExistingUser) {
                $user = $users->random();
                $userUuid = $user->uuid;
                $donorName = trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: null;
                $donorEmail = $user->email;
                $donorPhone = $user->phone_number;
            } else {
                $donorName = fake()->name();
                $donorEmail = fake()->unique()->safeEmail();
                $donorPhone = '+234'.fake()->numerify('##########');
            }

            Transaction::query()->create([
                'transaction_id' => $this->generateTransactionPublicId(),
                'campaign_uuid' => $campaign->uuid,
                'user_uuid' => $userUuid,
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'donor_phone' => $donorPhone,
                'is_anonymous' => $isAnonymous,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate_to_naira' => $rate,
                'amount_in_naira' => $amountInNaira,
                'status' => $status,
                'gateway' => $gateway,
                'gateway_reference' => strtoupper($gateway).'-'.fake()->bothify('??##??##??'),
                'metadata' => [
                    'donation_type' => random_int(0, 9) === 0 ? 'Recurring Donation' : 'One Time Donation',
                    'payment_method' => $paymentMethod,
                ],
                'paid_at' => $paidAt,
                'created_at' => $createdAt,
                'updated_at' => $paidAt ?? $createdAt,
            ]);
        }

        $this->command?->info('Seeded '.self::COUNT.' transactions.');
    }

    private function generateTransactionPublicId(): string
    {
        $result = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'transaction_id',
            'prefix' => 'TRN-',
            'idLength' => 12,
            'idType' => 'numalpha',
        ]);

        return is_array($result) ? 'TRN-'.strtoupper(bin2hex(random_bytes(4))) : (string) $result;
    }
}
