<?php

namespace Tests\Unit\Middleware;

use App\Enums\Api\ApiEncryptionMode;
use App\Http\Middleware\RequestResponseEncryptionMiddleware;
use App\Http\Resources\ApiUserResource;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Services\CryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Tests\TestCase;

final class RequestResponseEncryptionMiddlewareTest extends TestCase
{
    private const KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const IV = 'AAAAAAAAAAAAAAAAAAAAAA==';

    private ApiUserRepositoryInterface&MockInterface $repo;

    private RequestResponseEncryptionMiddleware $middleware;

    private ApiUserResource $userResourceBoth;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.api_encryption.middleware_enabled', true);
        Config::set('security.api_encryption.response_preview', false);

        $this->repo = Mockery::mock(ApiUserRepositoryInterface::class);

        $this->middleware = new RequestResponseEncryptionMiddleware($this->repo);

        $this->userResourceBoth = new ApiUserResource(self::makeStub(ApiEncryptionMode::Both));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * In-memory stand-in for {@see ApiUser} (no DB / container).
     */
    private static function makeStub(ApiEncryptionMode $mode): object
    {
        return new class('Test', 'test@example.com', 'test-client-key', self::KEY, self::IV, $mode)
        {
            public int $id = 1;

            public string $uuid = '00000000-0000-0000-0000-000000000001';

            public bool $is_active = true;

            public function __construct(
                public ?string $name,
                public string $email,
                public string $client_key,
                public string $encryption_key,
                public string $iv,
                public ApiEncryptionMode $encryption_mode,
            ) {}
        };
    }

    private function userWithMode(ApiEncryptionMode $mode): ApiUserResource
    {
        $stub = new class('Test', 'test2@example.com', 'test-client-key', self::KEY, self::IV, $mode)
        {
            public int $id = 2;

            public string $uuid = '00000000-0000-0000-0000-000000000002';

            public bool $is_active = true;

            public function __construct(
                public ?string $name,
                public string $email,
                public string $client_key,
                public string $encryption_key,
                public string $iv,
                public ApiEncryptionMode $encryption_mode,
            ) {}
        };

        return new ApiUserResource($stub);
    }

    /** @dataProvider bypassPathProvider */
    public function test_bypass_paths_skip_auth_and_crypto(string $path, string $method = 'POST'): void
    {
        $content = $method === 'GET' ? null : '{"data":"plain"}';
        $request = Request::create($path, $method, content: $content);
        $response = JsonResponse::fromJsonString('{"ok":true}');

        $result = $this->middleware->handle($request, fn () => $response);

        $this->repo->shouldNotReceive('findByClientKey');
        $this->assertSame('{"ok":true}', $result->getContent());
    }

    public static function bypassPathProvider(): array
    {
        return [
            'paystack webhook' => ['/api/v1/payment/paystack/webhook'],
            'flutterwave webhook' => ['/api/payment/flutterwave/webhook'],
            'flutterwave callback' => ['/api/payment/flutterwave/callback'],
            'stripe webhook' => ['/api/v1/payment/stripe/webhook'],
            'dev api registration' => ['/api/v1/dev/api-users'],
            'dev crypto encrypt' => ['/api/v1/dev/crypto/encrypt'],
            'guest recognition download' => ['/api/v1/public/recognitions/REC-001/download', 'GET'],
            'guest donation receipt download' => ['/api/v1/public/receipts/RCP-001/download', 'GET'],
            'guest tax receipt download' => ['/api/v1/public/receipts/RCP-001/tax/download', 'GET'],
            'guest blog report download' => ['/api/v1/public/blog/report/abc-123/download', 'GET'],
        ];
    }

    public function test_donation_and_checkout_routes_require_encryption_when_middleware_enabled(): void
    {
        $paths = [
            '/api/v1/donations/intent',
            '/api/v1/me/donations/intent',
            '/api/v1/donations/checkout',
            '/api/v1/donations/checkout/verify',
        ];

        foreach ($paths as $path) {
            $request = Request::create($path, 'POST', content: '{"data":"plain"}');
            $response = JsonResponse::fromJsonString('{"ok":true}');

            $result = $this->middleware->handle($request, fn () => $response);

            $this->assertSame(
                BaseResponse::HTTP_UNAUTHORIZED,
                $result->getStatusCode(),
                "Expected {$path} to require encryption.",
            );
        }
    }

    public function test_public_contact_requires_encryption_when_middleware_enabled(): void
    {
        $request = Request::create('/api/v1/public/contact', 'POST', content: '{"data":"plain"}');
        $response = JsonResponse::fromJsonString('{"ok":true}');

        $result = $this->middleware->handle($request, fn () => $response);

        $this->assertSame(BaseResponse::HTTP_UNAUTHORIZED, $result->getStatusCode());
    }

    public function test_missing_client_key_returns_401(): void
    {
        $request = Request::create('/api/something', 'GET');
        $result = $this->middleware->handle($request, fn () => new Response);

        $this->assertSame(BaseResponse::HTTP_UNAUTHORIZED, $result->getStatusCode());
    }

    public function test_unknown_client_key_returns_401(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('unknown-key')->once()->andReturn(null);

        $request = Request::create('/api/something', 'GET');
        $request->headers->set('X-ClientKey', 'unknown-key');

        $result = $this->middleware->handle($request, fn () => new Response);

        $this->assertSame(BaseResponse::HTTP_UNAUTHORIZED, $result->getStatusCode());
    }

    public function test_post_request_body_is_decrypted_before_reaching_controller(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $plainBody = json_encode(['amount' => 5000]);
        $encrypted = CryptoService::encryptAes($plainBody, self::KEY, self::IV);
        $requestBody = json_encode(['payload' => $encrypted]);

        $request = Request::create('/api/transactions', 'POST', content: $requestBody);
        $request->headers->set('X-ClientKey', 'test-client-key');
        $request->headers->set('Content-Type', 'application/json');

        $capturedData = null;

        $this->middleware->handle($request, function (Request $req) use (&$capturedData) {
            $capturedData = $req->input('amount');

            return JsonResponse::fromJsonString(json_encode(['status' => 'ok']));
        });

        $this->assertSame(5000, $capturedData);
    }

    public function test_response_body_is_encrypted(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $plainBody = json_encode(['status' => 'ok', 'balance' => 9999]);
        $request = Request::create('/api/balance', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => JsonResponse::fromJsonString($plainBody));
        $decoded = json_decode($result->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);

        $decrypted = CryptoService::decryptAes($decoded['response'], self::KEY, self::IV);
        $this->assertSame($plainBody, $decrypted);
    }

    public function test_encrypted_response_includes_preview_when_enabled(): void
    {
        Config::set('security.api_encryption.response_preview', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $plainBody = json_encode(['error' => false, 'message' => 'ok', 'data' => ['x' => 1]]);
        $request = Request::create('/api/balance', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => JsonResponse::fromJsonString($plainBody));
        $decoded = json_decode($result->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);
        $this->assertArrayHasKey('preview', $decoded);
        $this->assertSame(json_decode($plainBody, associative: true), $decoded['preview']);

        $decrypted = CryptoService::decryptAes($decoded['response'], self::KEY, self::IV);
        $this->assertSame($plainBody, $decrypted);
    }

    public function test_request_only_leaves_response_plaintext(): void
    {
        $user = $this->userWithMode(ApiEncryptionMode::RequestOnly);
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($user);

        $plainBody = json_encode(['status' => 'ok']);
        $request = Request::create('/api/balance', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => JsonResponse::fromJsonString($plainBody));

        $this->assertSame($plainBody, $result->getContent());
    }

    public function test_response_only_encrypts_outbound_and_accepts_plain_json_body(): void
    {
        $user = $this->userWithMode(ApiEncryptionMode::ResponseOnly);
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($user);

        $request = Request::create('/api/transactions', 'POST', content: json_encode(['amount' => 100]));
        $request->headers->set('X-ClientKey', 'test-client-key');
        $request->headers->set('Content-Type', 'application/json');

        $captured = null;

        $plainResponse = json_encode(['created' => true]);

        $result = $this->middleware->handle($request, function (Request $req) use (&$captured, $plainResponse) {
            $captured = $req->input('amount');

            return JsonResponse::fromJsonString($plainResponse);
        });

        $this->assertSame(100, $captured);

        $decoded = json_decode($result->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);
        $this->assertSame($plainResponse, CryptoService::decryptAes($decoded['response'], self::KEY, self::IV));
    }

    public function test_empty_response_is_passed_through_unchanged(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $request = Request::create('/api/nothing', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => new Response('', 204));

        $this->assertSame('', $result->getContent());
    }

    public function test_get_encrypted_query_string_is_decrypted(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $queryJson = json_encode(['search' => 'scholarships', 'page' => '2']);
        $enc = urlencode(CryptoService::encryptAes($queryJson, self::KEY, self::IV));

        $request = Request::create("/api/search?enc={$enc}", 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $capturedSearch = null;

        $this->middleware->handle($request, function (Request $req) use (&$capturedSearch) {
            $capturedSearch = $req->query('search');

            return JsonResponse::fromJsonString('{}');
        });

        $this->assertSame('scholarships', $capturedSearch);
    }

    public function test_invalid_encrypted_payload_returns_422(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $request = Request::create('/api/transactions', 'POST', content: '{"payload":"not-valid-cipher!!"}');
        $request->headers->set('X-ClientKey', 'test-client-key');
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->middleware->handle($request, fn () => new Response);

        $this->assertSame(BaseResponse::HTTP_UNPROCESSABLE_ENTITY, $result->getStatusCode());
    }

    public function test_override_user_receives_plaintext_response_when_enabled(): void
    {
        Config::set('security.override_users.enabled', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $plainBody = json_encode(['status' => 'ok', 'balance' => 9999]);
        $request = Request::create('/api/balance', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $overrideUser = new class {
            public string $email = 'admin-override@yopmail.com';
        };
        $request->setUserResolver(static fn () => $overrideUser);

        $result = $this->middleware->handle($request, fn () => JsonResponse::fromJsonString($plainBody));

        $this->assertSame($plainBody, $result->getContent());
    }

    public function test_non_override_user_still_receives_encrypted_response_when_override_enabled(): void
    {
        Config::set('security.override_users.enabled', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $plainBody = json_encode(['status' => 'ok']);
        $request = Request::create('/api/balance', 'GET');
        $request->headers->set('X-ClientKey', 'test-client-key');

        $regularUser = new class {
            public string $email = 'someone@example.com';
        };
        $request->setUserResolver(static fn () => $regularUser);

        $result = $this->middleware->handle($request, fn () => JsonResponse::fromJsonString($plainBody));

        $decoded = json_decode($result->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);
    }

    public function test_override_user_accepts_form_request_body(): void
    {
        Config::set('security.override_users.enabled', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $overrideUser = new class {
            public string $email = 'customer-override@yopmail.com';
        };

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'customer-override@yopmail.com',
            'password' => 'password',
            'client' => 'web',
        ]);
        $request->headers->set('X-ClientKey', 'test-client-key');
        $request->setUserResolver(static fn () => $overrideUser);

        $captured = null;
        $plainResponse = json_encode(['error' => false, 'message' => 'ok', 'data' => []]);

        $result = $this->middleware->handle($request, function (Request $req) use (&$captured, $plainResponse) {
            $captured = $req->input('email');

            return new Response($plainResponse);
        });

        $this->assertSame('customer-override@yopmail.com', $captured);
        $this->assertSame($plainResponse, $result->getContent());
    }

    public function test_override_user_accepts_plain_json_request_body(): void
    {
        Config::set('security.override_users.enabled', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $overrideUser = new class {
            public string $email = 'customer-override@yopmail.com';
        };

        $request = Request::create('/api/transactions', 'POST', content: json_encode(['amount' => 2500]));
        $request->headers->set('X-ClientKey', 'test-client-key');
        $request->headers->set('Content-Type', 'application/json');
        $request->setUserResolver(static fn () => $overrideUser);

        $captured = null;
        $plainResponse = json_encode(['created' => true]);

        $result = $this->middleware->handle($request, function (Request $req) use (&$captured, $plainResponse) {
            $captured = $req->input('amount');

            return new Response($plainResponse);
        });

        $this->assertSame(2500, $captured);
        $this->assertSame($plainResponse, $result->getContent());
    }

    public function test_login_with_form_body_is_rejected_when_mode_is_both(): void
    {
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'customer@example.com',
            'password' => 'password',
            'client' => 'web',
        ]);
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => new Response('{}'));

        $this->assertSame(BaseResponse::HTTP_UNPROCESSABLE_ENTITY, $result->getStatusCode());

        $decoded = json_decode($result->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);

        $plain = json_decode(CryptoService::decryptAes($decoded['response'], self::KEY, self::IV), true);
        $this->assertStringContainsString('application/json', (string) ($plain['message'] ?? ''));
    }

    public function test_form_body_is_rejected_for_request_only_mode(): void
    {
        $user = $this->userWithMode(ApiEncryptionMode::RequestOnly);
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($user);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => new Response('{}'));

        $this->assertSame(BaseResponse::HTTP_UNPROCESSABLE_ENTITY, $result->getStatusCode());
    }

    public function test_response_only_accepts_form_body(): void
    {
        $user = $this->userWithMode(ApiEncryptionMode::ResponseOnly);
        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($user);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'customer@example.com',
            'password' => 'password',
            'client' => 'web',
        ]);
        $request->headers->set('X-ClientKey', 'test-client-key');

        $capturedEmail = null;
        $plainResponse = json_encode(['error' => false, 'message' => 'ok', 'data' => []]);

        $result = $this->middleware->handle($request, function (Request $req) use (&$capturedEmail, $plainResponse) {
            $capturedEmail = $req->input('email');

            return JsonResponse::fromJsonString($plainResponse);
        });

        $this->assertSame('customer@example.com', $capturedEmail);

        $decoded = json_decode($result->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('response', $decoded);
    }

    public function test_non_auth_request_with_override_email_in_form_body_is_rejected_for_both_mode(): void
    {
        Config::set('security.override_users.enabled', true);

        $this->repo->shouldReceive('findByClientKey')->with('test-client-key')->once()->andReturn($this->userResourceBoth);

        $request = Request::create('/api/v1/users', 'POST', [
            'email' => 'customer-override@yopmail.com',
            'name' => 'Test',
        ]);
        $request->headers->set('X-ClientKey', 'test-client-key');

        $result = $this->middleware->handle($request, fn () => new Response('{}'));

        $this->assertSame(BaseResponse::HTTP_UNPROCESSABLE_ENTITY, $result->getStatusCode());
    }
}
