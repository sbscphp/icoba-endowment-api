<?php

namespace Tests\Feature\Admin\ContentManagement;

use App\Models\Ad;
use App\Models\AdImage;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

class AdOrientationTest extends TestCase
{
    use RefreshDatabase;

    private const CM = '/api/v1/admin/content-management/ads';

    private const PUBLIC = '/api/v1/public/ads';

    private const L1 = 'https://cdn.example.com/land-1.jpg';

    private const L2 = 'https://cdn.example.com/land-2.jpg';

    private const P1 = 'https://cdn.example.com/port-1.jpg';

    private const P2 = 'https://cdn.example.com/port-2.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);

        Sanctum::actingAs(Admin::query()->create([
            'name' => 'Content Admin',
            'email' => 'cm-ads@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]));
    }

    public function test_create_stores_landscape_and_portrait_images_separately(): void
    {
        $response = $this->postJson(self::CM, $this->payload([
            'landscape_images' => [self::L1, self::L2],
            'portrait_images' => [self::P1],
        ]))->assertOk();

        $data = $response->json('data');
        $this->assertSame([self::L1, self::L2], array_column($data['landscape_images'], 'image_url'));
        $this->assertSame([self::P1], array_column($data['portrait_images'], 'image_url'));
        $this->assertSame(3, $data['images_count']);
        $this->assertSame(2, $data['landscape_images_count']);
        $this->assertSame(1, $data['portrait_images_count']);
        $this->assertSame(['landscape', 'landscape', 'portrait'], array_column($data['images'], 'orientation'));

        $this->assertSame(2, AdImage::query()->where('orientation', 'landscape')->count());
        $this->assertSame(1, AdImage::query()->where('orientation', 'portrait')->count());
    }

    public function test_legacy_images_key_is_treated_as_landscape(): void
    {
        $response = $this->postJson(self::CM, $this->payload(['images' => [self::L1]]))->assertOk();

        $this->assertSame([self::L1], array_column($response->json('data.landscape_images'), 'image_url'));
        $this->assertSame([], $response->json('data.portrait_images'));
    }

    public function test_create_requires_at_least_one_image_in_either_orientation(): void
    {
        $this->postJson(self::CM, $this->payload([]))->assertStatus(422);
        $this->postJson(self::CM, $this->payload(['landscape_images' => [], 'portrait_images' => []]))->assertStatus(422);
        $this->postJson(self::CM, $this->payload(['portrait_images' => [self::P1]]))->assertOk();
    }

    public function test_sync_only_touches_the_orientation_that_was_sent(): void
    {
        $id = $this->createAd(['landscape_images' => [self::L1], 'portrait_images' => [self::P1]]);

        $response = $this->putJson(self::CM.'/'.$id.'/images', ['portrait_images' => [self::P2, self::P1]])->assertOk();

        $this->assertSame([self::L1], array_column($response->json('data.landscape_images'), 'image_url'));
        $this->assertSame([self::P2, self::P1], array_column($response->json('data.portrait_images'), 'image_url'));
        $this->assertSame([0, 1], array_column($response->json('data.portrait_images'), 'sort_order'));
    }

    public function test_sync_can_clear_one_orientation_but_not_both(): void
    {
        $id = $this->createAd(['landscape_images' => [self::L1], 'portrait_images' => [self::P1]]);

        $this->putJson(self::CM.'/'.$id.'/images', ['portrait_images' => []])->assertOk();
        $this->assertSame(0, AdImage::query()->where('orientation', 'portrait')->count());
        $this->assertSame(1, AdImage::query()->where('orientation', 'landscape')->count());

        $this->putJson(self::CM.'/'.$id.'/images', ['landscape_images' => []])->assertStatus(422);
        $this->assertSame(1, AdImage::query()->where('orientation', 'landscape')->count());

        $this->putJson(self::CM.'/'.$id.'/images', [])->assertStatus(422);
    }

    public function test_update_reconciles_per_orientation_lists(): void
    {
        $id = $this->createAd(['landscape_images' => [self::L1, self::L2]]);

        $response = $this->patchJson(self::CM.'/'.$id, [
            'landscape_images' => [self::L2],
            'portrait_images' => [self::P1],
        ])->assertOk();

        $this->assertSame([self::L2], array_column($response->json('data.landscape_images'), 'image_url'));
        $this->assertSame([self::P1], array_column($response->json('data.portrait_images'), 'image_url'));
    }

    public function test_admin_list_counts_images_per_orientation(): void
    {
        $this->createAd(['landscape_images' => [self::L1, self::L2], 'portrait_images' => [self::P1]]);

        $row = $this->getJson(self::CM)->assertOk()->json('data.data.0');

        $this->assertSame(3, $row['images_count']);
        $this->assertSame(2, $row['landscape_images_count']);
        $this->assertSame(1, $row['portrait_images_count']);
    }

    public function test_public_feed_filters_by_orientation(): void
    {
        $both = $this->createAd(['title' => 'Both', 'landscape_images' => [self::L1], 'portrait_images' => [self::P1]]);
        $landscapeOnly = $this->createAd(['title' => 'Landscape only', 'landscape_images' => [self::L2]]);

        $portrait = $this->getJson(self::PUBLIC.'?orientation=portrait')->assertOk()->json('data');
        $this->assertSame('portrait', $portrait['orientation']);
        $this->assertCount(1, $portrait['ads']);
        $this->assertSame($both, $portrait['ads'][0]['ad_id']);
        $this->assertSame([self::P1], $portrait['ads'][0]['images']);
        $this->assertSame('portrait', $portrait['ads'][0]['orientation']);
        $this->assertArrayNotHasKey('landscape_images', $portrait['ads'][0]);

        $landscape = $this->getJson(self::PUBLIC.'?orientation=LANDSCAPE')->assertOk()->json('data');
        $this->assertSame('landscape', $landscape['orientation']);
        $this->assertSame([$both, $landscapeOnly], array_column($landscape['ads'], 'ad_id'));
        $this->assertSame([[self::L1], [self::L2]], array_column($landscape['ads'], 'images'));

        $this->getJson(self::PUBLIC.'?orientation=square')->assertStatus(422);
    }

    public function test_public_feed_without_orientation_returns_both_galleries(): void
    {
        $this->createAd(['landscape_images' => [self::L1], 'portrait_images' => [self::P1]]);

        $data = $this->getJson(self::PUBLIC)->assertOk()->json('data');

        $this->assertNull($data['orientation']);
        $this->assertArrayHasKey('ads_transition_seconds', $data);
        $ad = $data['ads'][0];
        $this->assertSame([self::L1, self::P1], $ad['images']);
        $this->assertSame([self::L1], $ad['landscape_images']);
        $this->assertSame([self::P1], $ad['portrait_images']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'title' => 'Ad',
            'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAd(array $overrides): string
    {
        $this->postJson(self::CM, $this->payload($overrides))->assertOk();

        return (string) Ad::query()->latest('id')->value('uuid');
    }
}
