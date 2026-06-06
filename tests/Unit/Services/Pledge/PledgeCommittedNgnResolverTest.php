<?php

namespace Tests\Unit\Services\Pledge;

use App\Models\ExchangeRate;
use App\Services\Pledge\PledgeCommittedNgnResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PledgeCommittedNgnResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_at_capture_uses_todays_exchange_rate_from_database(): void
    {
        Carbon::setTestNow('2026-05-30 12:00:00');

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_to_naira' => 1550.50,
            'effective_date' => '2026-05-30',
            'source' => 'open.er-api.com',
        ]);

        $result = PledgeCommittedNgnResolver::atCapture(100.0, 'USD');

        $this->assertSame(155050.0, $result['committed_amount_ngn']);
        $this->assertSame(1550.5, $result['exchange_rate_to_naira']);

        Carbon::setTestNow();
    }

    public function test_at_capture_rejects_unsupported_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported currency: GHS');

        PledgeCommittedNgnResolver::atCapture(100.0, 'GHS');
    }
}
