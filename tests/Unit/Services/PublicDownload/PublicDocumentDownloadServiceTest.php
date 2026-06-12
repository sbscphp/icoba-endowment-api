<?php

namespace Tests\Unit\Services\PublicDownload;

use App\Enums\IssuedCertificateStatus;
use App\Enums\PublicDocumentType;
use App\Exceptions\ApiException;
use App\Models\DonorRecognition;
use App\Models\PublicDocumentDownloadToken;
use App\Services\PublicDownload\PublicDocumentDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicDocumentDownloadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.com',
            'app.frontend_url' => 'https://app.example.com',
        ]);
    }

    public function test_issue_recognition_token_builds_frontend_download_url(): void
    {
        $recognition = $this->createRecognition();

        $service = app(PublicDocumentDownloadService::class);
        $tokenRecord = $service->issueRecognitionCertificateToken($recognition);

        $this->assertSame(PublicDocumentType::RecognitionCertificate, $tokenRecord->document_type);
        $this->assertSame($recognition->uuid, $tokenRecord->subject_uuid);
        $this->assertSame(
            'https://app.example.com/download/'.$tokenRecord->token,
            $service->publicDownloadUrl($tokenRecord),
        );
        $this->assertSame(
            'https://api.example.com/api/v1/public/downloads/'.$tokenRecord->token,
            $service->apiDownloadUrl($tokenRecord),
        );
    }

    public function test_public_download_url_falls_back_when_frontend_url_is_api_host(): void
    {
        config([
            'app.url' => 'https://api.icoba-endowment.sbscuk.co.uk',
            'app.frontend_url' => 'https://api.icoba-endowment.sbscuk.co.uk',
        ]);

        $recognition = $this->createRecognition();
        $service = app(PublicDocumentDownloadService::class);
        $tokenRecord = $service->issueRecognitionCertificateToken($recognition);

        $this->assertSame(
            'https://icoba-endowment.onrender.com/download/'.$tokenRecord->token,
            $service->publicDownloadUrl($tokenRecord),
        );
    }

    public function test_issue_token_reuses_active_record_for_same_document(): void
    {
        $recognition = $this->createRecognition();
        $service = app(PublicDocumentDownloadService::class);

        $first = $service->issueRecognitionCertificateToken($recognition);
        $second = $service->issueRecognitionCertificateToken($recognition);

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, PublicDocumentDownloadToken::query()->count());
    }

    public function test_stream_by_unknown_token_returns_not_found(): void
    {
        $service = app(PublicDocumentDownloadService::class);

        try {
            $service->streamByToken('unknown-token-value-that-does-not-exist-at-all');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('document_not_found', $exception->payload['code'] ?? null);
        }
    }

    public function test_stream_by_revoked_token_returns_revoked_error(): void
    {
        $recognition = $this->createRecognition();
        $service = app(PublicDocumentDownloadService::class);
        $tokenRecord = $service->issueRecognitionCertificateToken($recognition);
        $tokenRecord->forceFill(['revoked_at' => now()])->save();

        try {
            $service->streamByToken($tokenRecord->token);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('document_revoked', $exception->payload['code'] ?? null);
        }
    }

    public function test_stream_by_token_rejects_revoked_recognition(): void
    {
        $recognition = $this->createRecognition([
            'status' => IssuedCertificateStatus::REVOKED,
        ]);
        $service = app(PublicDocumentDownloadService::class);
        $tokenRecord = PublicDocumentDownloadToken::query()->create([
            'token' => Str::random(48),
            'document_type' => PublicDocumentType::RecognitionCertificate,
            'subject_uuid' => $recognition->uuid,
        ]);

        try {
            $service->streamByToken($tokenRecord->token);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('document_revoked', $exception->payload['code'] ?? null);
            $this->assertStringContainsString('revoked', strtolower($exception->getMessage()));
        }
    }

    public function test_revoke_tokens_for_recognition_marks_active_tokens_revoked(): void
    {
        $recognition = $this->createRecognition();
        $service = app(PublicDocumentDownloadService::class);
        $tokenRecord = $service->issueRecognitionCertificateToken($recognition);

        $service->revokeTokensForRecognition($recognition);

        $this->assertNotNull($tokenRecord->fresh()?->revoked_at);
    }

    public function test_public_download_endpoint_returns_json_for_invalid_token(): void
    {
        $response = $this->getJson('/api/v1/public/downloads/'.str_repeat('a', 48));

        $response->assertStatus(404)
            ->assertJsonPath('error', true)
            ->assertJsonPath('data.code', 'document_not_found');
    }

    private function createRecognition(array $overrides = []): DonorRecognition
    {
        $tier = \App\Models\TierConfiguration::query()->create([
            'name' => 'Bronze',
            'min_amount' => 100000,
            'is_active' => true,
        ]);

        return DonorRecognition::query()->create(array_merge([
            'recognition_number' => 'ICOBA-REC-2026-'.Str::upper(Str::random(8)),
            'donor_key' => 'donor-'.Str::random(8),
            'awardee_name' => 'Sample Donor',
            'tier_uuid' => $tier->uuid,
            'cumulative_amount_ngn' => 1000000,
            'issued_at' => now(),
            'status' => IssuedCertificateStatus::AUTO_ISSUED,
        ], $overrides));
    }
}
