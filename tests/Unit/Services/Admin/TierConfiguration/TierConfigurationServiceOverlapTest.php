<?php

namespace Tests\Unit\Services\Admin\TierConfiguration;

use App\Exceptions\ApiException;
use App\Models\TierConfiguration;
use App\Services\Admin\TierConfiguration\TierConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TierConfigurationServiceOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_overlap_error_lists_all_conflicting_tiers_and_neighbor_suggestions(): void
    {
        $silver = $this->createTier('Silver Supporter', 10_000_000, 99_999_999.99, 3);
        $gold = $this->createTier('Gold Benefactor', 100_000_000, 499_999_999.99, 4);

        $service = app(TierConfigurationService::class);

        try {
            $service->create([
                'name' => 'Expanded Silver',
                'min_amount' => 95_000_000,
                'max_amount' => 150_000_000,
                'sort_order' => 3,
            ]);
            $this->fail('Expected ApiException for overlapping tier range.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('Silver Supporter', $e->getMessage());
            $this->assertStringContainsString('Gold Benefactor', $e->getMessage());

            $payload = $e->payload;
            $this->assertIsArray($payload);
            $this->assertCount(2, $payload['overlapping_tiers']);
            $this->assertSame($silver->uuid, $payload['overlapping_tiers'][0]['uuid']);
            $this->assertSame($gold->uuid, $payload['overlapping_tiers'][1]['uuid']);
            $this->assertSame(95_000_000.0, $payload['requested_range']['min_amount']);
            $this->assertSame(150_000_000.0, $payload['requested_range']['max_amount']);

            $adjustments = collect($payload['suggested_adjustments']);
            $this->assertTrue($adjustments->contains(fn (array $row): bool => $row['tier_id'] === $silver->uuid
                && $row['field'] === 'max_amount'
                && $row['suggested_value'] === 94_999_999.99));
            $this->assertTrue($adjustments->contains(fn (array $row): bool => $row['tier_id'] === $gold->uuid
                && $row['field'] === 'min_amount'
                && $row['suggested_value'] === 150_000_000.01));
        }
    }

    public function test_create_allows_adjacent_ranges_with_gap(): void
    {
        $this->createTier('Silver Supporter', 10_000_000, 99_999_999.99, 3);

        $service = app(TierConfigurationService::class);
        $tier = $service->create([
            'name' => 'Gold Benefactor',
            'min_amount' => 100_000_000,
            'max_amount' => 499_999_999.99,
            'sort_order' => 4,
        ]);

        $this->assertSame('Gold Benefactor', $tier->name);
    }

    public function test_update_overlap_error_excludes_current_tier_from_conflicts(): void
    {
        $silver = $this->createTier('Silver Supporter', 10_000_000, 99_999_999.99, 3);
        $this->createTier('Gold Benefactor', 100_000_000, 499_999_999.99, 4);

        $service = app(TierConfigurationService::class);

        try {
            $service->update($silver->uuid, [
                'min_amount' => 95_000_000,
                'max_amount' => 150_000_000,
            ]);
            $this->fail('Expected ApiException for overlapping tier range.');
        } catch (ApiException $e) {
            $payload = $e->payload;
            $this->assertCount(1, $payload['overlapping_tiers']);
            $this->assertSame('Gold Benefactor', $payload['overlapping_tiers'][0]['name']);
        }
    }

    private function createTier(string $name, float $min, ?float $max, int $sortOrder): TierConfiguration
    {
        return TierConfiguration::query()->create([
            'name' => $name,
            'min_amount' => $min,
            'max_amount' => $max,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }
}
