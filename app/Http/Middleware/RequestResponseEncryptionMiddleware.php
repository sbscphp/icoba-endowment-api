<?php

namespace App\Http\Middleware;

use App\Enums\Api\ApiEncryptionMode;
use App\Http\Resources\Developer\ApiUserResource;
use App\Http\Resources\Developer\EncryptedPayloadResource;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Responser\JsonResponser;
use App\Services\CryptoService;
use App\Support\EncryptionOverrideUsers;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Request/response AES-256-CBC encryption middleware (per ApiUser.encryption_mode).
 */
final readonly class RequestResponseEncryptionMiddleware
{
    private const CLIENT_KEY_HEADER = 'X-ClientKey';

    /**
     * Returned as {@see InvalidArgumentException} message and mapped to HTTP 422 in {@see handle()}.
     */
    private const NON_JSON_INBOUND_BODY_MESSAGE = 'Request body must be application/json with a JSON object or encrypted payload envelope.';

    /**
     * Route path suffixes that bypass encryption entirely.
     *
     * @var list<string>
     */
    private const BYPASS_SUFFIXES = [
        '/paystack/webhook',
        '/paystack/callback',
        '/flutterwave/webhook',
        '/flutterwave/callback',
        '/stripe/webhook',
    ];

    /**
     * Route path substrings that bypass encryption entirely.
     *
     * Payment provider webhook/callback routes use {@see BYPASS_SUFFIXES} instead.
     * Dev provisioning routes accept plaintext for bootstrap. All other routes
     * (including donations, public API, and checkout) require X-ClientKey when
     * middleware is enabled.
     *
     * @var list<string>
     */
    private const BYPASS_CONTAINS = [
        '/dev/api-users',
        '/dev/crypto',
    ];

    /**
     * Route path regex patterns that bypass encryption entirely.
     *
     * Used for public guest download endpoints that are opened directly in a
     * browser from an email link (donation receipts, tax receipts, donor
     * recognition certificates, campaign update reports). These responses are
     * streamed binary PDFs and authenticate via their own per-resource
     * `?token=` query parameter, so they cannot rely on the `X-ClientKey`
     * header that the encryption middleware otherwise requires.
     *
     * @var list<string>
     */
    private const BYPASS_REGEX = [
        '#^/api/v1/public/recognitions/[^/]+/download$#i',
        '#^/api/v1/public/receipts/[^/]+/download$#i',
        '#^/api/v1/public/receipts/[^/]+/tax/download$#i',
        '#^/api/v1/public/blog/report/[^/]+/download$#i',
    ];

    public function __construct(
        private ApiUserRepositoryInterface $apiUsers,
    ) {}

    public function handle(Request $request, Closure $next): BaseResponse
    {
        if (! config('security.api_encryption.middleware_enabled')) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $this->sanitiseHeaders($request);

        $clientKey = $request->header(self::CLIENT_KEY_HEADER);

        if (blank($clientKey)) {
            return $this->unauthorised('Missing client key header.');
        }

        $apiUser = $this->apiUsers->findByClientKey($clientKey);

        if ($apiUser === null) {
            Log::warning('Encryption middleware: unknown or inactive client key.', [
                'key_prefix' => substr($clientKey, 0, 6).'…',
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorised('Invalid or inactive client key.');
        }

        $request->attributes->set('api_user', $apiUser);

        try {
            $this->decryptInboundRequest($request, $apiUser);
        } catch (JsonException $e) {
            Log::warning('Encryption middleware: invalid JSON in inbound request.', [
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return $this->finalizeOutboundResponse(
                $request,
                $apiUser,
                response()->json(
                    ['message' => 'Request body must be valid JSON.'],
                    BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
                ),
            );
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === self::NON_JSON_INBOUND_BODY_MESSAGE) {
                return $this->finalizeOutboundResponse(
                    $request,
                    $apiUser,
                    response()->json(
                        ['message' => $e->getMessage()],
                        BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
                    ),
                );
            }

            Log::error('Encryption middleware: failed to decrypt inbound payload.', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return $this->finalizeOutboundResponse(
                $request,
                $apiUser,
                response()->json(
                    ['message' => 'Invalid encrypted payload.'],
                    BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
                ),
            );
        } catch (RuntimeException $e) {
            Log::error('Encryption middleware: failed to decrypt inbound payload.', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return $this->finalizeOutboundResponse(
                $request,
                $apiUser,
                response()->json(
                    ['message' => 'Invalid encrypted payload.'],
                    BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
                ),
            );
        }

        return $this->finalizeOutboundResponse($request, $apiUser, $next($request));
    }

    private function finalizeOutboundResponse(
        Request $request,
        ApiUserResource $apiUser,
        BaseResponse $response,
    ): BaseResponse {
        if (EncryptionOverrideUsers::shouldBypassOutboundEncryption($request)) {
            return $response;
        }

        try {
            return $this->encryptOutboundResponse($response, $apiUser);
        } catch (RuntimeException $e) {
            Log::critical('Encryption middleware: failed to encrypt outbound response.', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);

            return response()->json(
                ['message' => 'Internal server error.'],
                BaseResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function shouldBypass(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        foreach (self::BYPASS_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($path), strtolower($suffix))) {
                return true;
            }
        }

        foreach (self::BYPASS_CONTAINS as $needle) {
            if (str_contains(strtolower($path), strtolower($needle))) {
                return true;
            }
        }

        foreach (self::BYPASS_REGEX as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function sanitiseHeaders(Request $request): void
    {
        foreach ($request->headers->all() as $name => $values) {
            $clean = array_map(
                static fn (string $v): string => str_replace(["\r", "\n"], '', $v),
                $values,
            );
            $request->headers->set($name, $clean);
        }
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function decryptInboundRequest(Request $request, ApiUserResource $apiUser): void
    {
        if (EncryptionOverrideUsers::shouldBypassInboundDecryption($request)) {
            $this->normalisePlainInboundRequest($request);

            return;
        }

        $mode = $apiUser->getEncryptionMode();
        $method = strtoupper($request->method());

        if (in_array($method, ['GET', 'DELETE'], strict: true)) {
            $this->decryptInboundQueryOrSkip($request, $apiUser, $mode);

            return;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], strict: true)) {
            $this->decryptInboundBody($request, $apiUser, $mode);
        }
    }

    /**
     * @throws JsonException
     */
    private function normalisePlainInboundRequest(Request $request): void
    {
        $method = strtoupper($request->method());

        if (! in_array($method, ['POST', 'PUT', 'PATCH'], strict: true)) {
            return;
        }

        $raw = $request->getContent();

        if (blank($raw)) {
            return;
        }

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

            if (is_array($data)) {
                $request->replace($data);
            }
        } catch (JsonException) {
            // Multipart / form-urlencoded bodies are already parsed into the request bag.
        }
    }

    private function decryptInboundQueryOrSkip(Request $request, ApiUserResource $apiUser, ApiEncryptionMode $mode): void
    {
        if (! $mode->decryptsInbound()) {
            return;
        }

        $enc = $request->query('enc');

        if (! is_string($enc) || $enc === '') {
            return;
        }

        $json = CryptoService::decryptAes($enc, $apiUser->getEncryptionKey(), $apiUser->getIv());
        $this->rewriteQueryFromJson($request, $json);
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function decryptInboundBody(Request $request, ApiUserResource $apiUser, ApiEncryptionMode $mode): void
    {
        if ($mode->decryptsInbound()) {
            $this->rejectNonJsonInboundBody($request);
        }

        $raw = $request->getContent();

        if (blank($raw)) {
            return;
        }

        $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }

        if ($mode === ApiEncryptionMode::Both) {
            $envelope = EncryptedPayloadResource::fromRequestData($data);
            $plain = CryptoService::decryptAes($envelope->payload, $apiUser->getEncryptionKey(), $apiUser->getIv());
            $request->replace(json_decode($plain, associative: true, flags: JSON_THROW_ON_ERROR));

            return;
        }

        if ($mode === ApiEncryptionMode::RequestOnly) {
            if (EncryptedPayloadResource::isEncryptedEnvelope($data)) {
                $envelope = EncryptedPayloadResource::fromRequestData($data);
                $plain = CryptoService::decryptAes($envelope->payload, $apiUser->getEncryptionKey(), $apiUser->getIv());
                $request->replace(json_decode($plain, associative: true, flags: JSON_THROW_ON_ERROR));

                return;
            }

            $request->replace($data);

            return;
        }

        // ResponseOnly: plaintext JSON request bodies.
        $request->replace($data);
    }

    /**
     * Multipart and form-urlencoded bodies are parsed by PHP with an empty raw stream, which would
     * otherwise skip decryption while still reaching controllers in plaintext.
     *
     * @throws InvalidArgumentException
     */
    private function rejectNonJsonInboundBody(Request $request): void
    {
        if (! blank($request->getContent())) {
            return;
        }

        if ($request->request->count() > 0 || $request->files->count() > 0) {
            throw new InvalidArgumentException(self::NON_JSON_INBOUND_BODY_MESSAGE);
        }
    }

    private function encryptOutboundResponse(BaseResponse $response, ApiUserResource $apiUser): BaseResponse
    {
        if (! $apiUser->getEncryptionMode()->encryptsOutbound()) {
            return $response;
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getContent();

        if ($body === false || trim($body) === '') {
            return $response;
        }

        $encrypted = CryptoService::encryptAes($body, $apiUser->getEncryptionKey(), $apiUser->getIv());

        $envelopePayload = ['response' => $encrypted];

        if (JsonResponser::encryptedResponsePreviewEnabled()) {
            $envelopePayload['preview'] = $this->decodeBodyForEncryptionPreview($body);
        }

        $response->setContent(json_encode($envelopePayload, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @throws JsonException
     * @throws InvalidArgumentException
     */
    private function decodeBodyForEncryptionPreview(string $body): mixed
    {
        try {
            return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $trimmed = $body;
            if (strlen($trimmed) > 1024) {
                $trimmed = substr($trimmed, 0, 1024).'…';
            }

            return [
                '_note' => 'Response body was not valid JSON.',
                'raw' => $trimmed,
            ];
        }
    }

    private function rewriteQueryFromJson(Request $request, string $json): void
    {
        $params = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($params)) {
            throw new InvalidArgumentException('Decrypted query must be a JSON object.');
        }

        $request->query->replace($params);
        $request->server->set('QUERY_STRING', Arr::query($params));
    }

    private function unauthorised(string $message): JsonResponse
    {
        return response()->json(['message' => $message], BaseResponse::HTTP_UNAUTHORIZED);
    }
}
