<?php

namespace Tests\Feature\Admin\PaymentFeeRule;

use App\Enums\AuditActionEnum;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PaymentFeeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

final class PaymentFeeRuleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/payment-fee-rules';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);
        config(['platform_charges.enabled' => true]);

        $admin = Admin::query()->create([
            'name' => 'Fee Admin',
            'email' => 'fee-admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);

        Sanctum::actingAs($admin);
    }

    public function test_create_rule_and_list_it(): void
    {
        $this->postJson(self::BASE, [
            'name' => 'Service fee NGN',
            'currency' => 'ngn',
            'min_amount' => 0.01,
            'percentage' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.percentage', '3.0000')
            ->assertJsonPath('data.fixed_amount', '0.00')
            ->assertJsonPath('data.status', 'active');

        $this->getJson(self::BASE.'?filters[currency]=NGN')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Service fee NGN')
            ->assertJsonPath('data.data.0.transactions_count', 0);

        $this->getJson(self::BASE.'?filters[currency]=USD')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->assertTrue(AuditLog::query()->where('action', AuditActionEnum::PAYMENT_FEE_RULE_CREATED->value)->exists());
    }

    public function test_cap_can_be_kept_in_naira_on_a_foreign_currency_rule(): void
    {
        $response = $this->postJson(self::BASE, [
            'name' => 'Service fee USD',
            'currency' => 'USD',
            'min_amount' => 0.01,
            'percentage' => 3,
            'cap_amount' => 2000,
            'cap_currency' => 'ngn',
        ])
            ->assertCreated()
            ->assertJsonPath('data.cap_amount', '2000.00')
            ->assertJsonPath('data.cap_currency', 'NGN');

        $ruleId = (string) $response->json('data.rule_id');

        // Clearing the cap also clears its currency.
        $this->patchJson(self::BASE.'/'.$ruleId, ['cap_amount' => null])
            ->assertOk()
            ->assertJsonPath('data.cap_amount', null)
            ->assertJsonPath('data.cap_currency', null);

        // Without cap_currency the cap is in the rule currency.
        $this->patchJson(self::BASE.'/'.$ruleId, ['cap_amount' => 5])
            ->assertOk()
            ->assertJsonPath('data.cap_amount', '5.00')
            ->assertJsonPath('data.cap_currency', 'USD');
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->postJson(self::BASE, ['name' => 'x', 'currency' => 'XYZ', 'percentage' => 3])->assertStatus(422);
        $this->postJson(self::BASE, ['name' => 'x', 'currency' => 'NGN', 'cap_amount' => 2000, 'cap_currency' => 'XYZ'])->assertStatus(422);
        $this->postJson(self::BASE, ['name' => 'x', 'currency' => 'NGN', 'percentage' => 100])->assertStatus(422);
        $this->postJson(self::BASE, ['name' => 'x', 'currency' => 'NGN', 'min_amount' => 100, 'max_amount' => 50])->assertStatus(422);
    }

    public function test_overlapping_active_bands_are_rejected(): void
    {
        $this->postJson(self::BASE, [
            'name' => 'NGN small',
            'currency' => 'NGN',
            'min_amount' => 0.01,
            'max_amount' => 2499.99,
            'percentage' => 3,
        ])->assertCreated();

        $this->postJson(self::BASE, [
            'name' => 'NGN clash',
            'currency' => 'NGN',
            'min_amount' => 2000,
            'percentage' => 2,
        ])->assertStatus(422);

        // Adjacent band is fine, and another currency never clashes.
        $this->postJson(self::BASE, [
            'name' => 'NGN large',
            'currency' => 'NGN',
            'min_amount' => 2500,
            'percentage' => 2,
        ])->assertCreated();

        $this->postJson(self::BASE, [
            'name' => 'USD',
            'currency' => 'USD',
            'min_amount' => 0.01,
            'percentage' => 3,
        ])->assertCreated();
    }

    public function test_update_toggle_and_delete_are_audited(): void
    {
        $rule = PaymentFeeRule::query()->create([
            'name' => 'Service fee NGN',
            'currency' => 'NGN',
            'min_amount' => 0.01,
            'percentage' => 3,
            'is_active' => true,
        ]);

        $this->patchJson(self::BASE.'/'.$rule->uuid, ['percentage' => 2.5])
            ->assertOk()
            ->assertJsonPath('data.percentage', '2.5000');

        $this->patchJson(self::BASE.'/'.$rule->uuid.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson(self::BASE.'/'.$rule->uuid)->assertOk();
        $this->assertSoftDeleted('payment_fee_rules', ['uuid' => $rule->uuid]);

        foreach ([
            AuditActionEnum::PAYMENT_FEE_RULE_UPDATED,
            AuditActionEnum::PAYMENT_FEE_RULE_STATUS_TOGGLED,
            AuditActionEnum::PAYMENT_FEE_RULE_DELETED,
        ] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action->value)->exists(), $action->value.' not audited');
        }
    }

    public function test_preview_matches_public_quote(): void
    {
        PaymentFeeRule::query()->create([
            'name' => 'Service fee NGN',
            'currency' => 'NGN',
            'min_amount' => 0.01,
            'percentage' => 3,
            'is_active' => true,
        ]);

        $this->getJson(self::BASE.'/preview?amount=5000&currency=NGN')
            ->assertOk()
            ->assertJsonPath('data.donation_amount', '5000.00')
            ->assertJsonPath('data.fee_amount', '150.00')
            ->assertJsonPath('data.total_amount', '5150.00');

        $this->getJson(self::BASE.'/options')
            ->assertOk()
            ->assertJsonPath('data.charges_enabled', true)
            ->assertJsonPath('data.currencies.0', 'NGN');
    }
}
