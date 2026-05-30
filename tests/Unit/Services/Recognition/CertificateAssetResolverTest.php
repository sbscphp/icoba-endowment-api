<?php

namespace Tests\Unit\Services\Recognition;

use App\Services\Recognition\CertificateAssetResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CertificateAssetResolverTest extends TestCase
{
    public function test_resolve_returns_data_uri_for_remote_image(): void
    {
        Http::fake([
            'https://cdn.example.test/bg.png' => Http::response('png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $resolved = app(CertificateAssetResolver::class)->resolve('https://cdn.example.test/bg.png');

        $this->assertSame('https://cdn.example.test/bg.png', $resolved['url']);
        $this->assertSame('data:image/png;base64,'.base64_encode('png-bytes'), $resolved['data_uri']);
    }

    public function test_resolve_returns_local_public_asset_without_http(): void
    {
        Http::fake();

        $relative = '/assets/logo/'.basename(public_path('assets/logo/icoba-endowment.png'));
        $resolved = app(CertificateAssetResolver::class)->resolve(
            rtrim((string) config('app.url'), '/').$relative,
        );

        $this->assertNotNull($resolved['data_uri']);
        $this->assertStringStartsWith('data:image/', $resolved['data_uri']);
        Http::assertNothingSent();
    }

    public function test_resolve_keeps_url_when_download_fails(): void
    {
        Http::fake([
            'https://cdn.example.test/missing.png' => Http::response('', 404),
        ]);

        $resolved = app(CertificateAssetResolver::class)->resolve('https://cdn.example.test/missing.png');

        $this->assertSame('https://cdn.example.test/missing.png', $resolved['url']);
        $this->assertNull($resolved['data_uri']);
    }

    public function test_resolve_embeds_optimized_cloudinary_variant_for_large_original(): void
    {
        Http::fake([
            'https://res.cloudinary.com/demo/image/upload/v1/certificates/bg.png' => Http::response(str_repeat('a', 600_000), 200, [
                'Content-Type' => 'image/png',
            ]),
            'https://res.cloudinary.com/demo/image/upload/w_900,q_auto:good,f_auto/v1/certificates/bg.png' => Http::response('optimized-png', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        config(['recognitions.certificate_asset_max_embed_bytes' => 512_000]);

        $resolved = app(CertificateAssetResolver::class)->resolve(
            'https://res.cloudinary.com/demo/image/upload/v1/certificates/bg.png',
        );

        $this->assertSame(
            'https://res.cloudinary.com/demo/image/upload/w_900,q_auto:good,f_auto/v1/certificates/bg.png',
            $resolved['url'],
        );
        $this->assertSame('data:image/png;base64,'.base64_encode('optimized-png'), $resolved['data_uri']);
    }

    public function test_resolve_keeps_url_only_when_optimized_variant_is_still_too_large(): void
    {
        Http::fake([
            'https://cdn.example.test/large.png' => Http::response(str_repeat('a', 600_000), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        config(['recognitions.certificate_asset_max_embed_bytes' => 512_000]);

        $resolved = app(CertificateAssetResolver::class)->resolve('https://cdn.example.test/large.png');

        $this->assertSame('https://cdn.example.test/large.png', $resolved['url']);
        $this->assertNull($resolved['data_uri']);
    }
}
