<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developer\CryptoHelperDecryptRequest;
use App\Http\Requests\Developer\CryptoHelperEncryptRequest;
use App\Http\Resources\Developer\EncryptedPayloadResource;
use App\Http\Resources\Developer\ApiUserResource;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Responser\JsonResponser;
use App\Services\CryptoService;
use Illuminate\Http\Request;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Local/dev helpers: encrypt plaintext or decrypt an API response envelope using a consumer's keys.
 */
final class ApiCryptoHelperController extends Controller
{
    private const DEV_SECRET_HEADER = 'X-Dev-Api-User-Secret';

    private const CLIENT_KEY_HEADER = 'X-ClientKey';

    public function __construct(
        private readonly ApiUserRepositoryInterface $apiUsers,
    ) {}

    public function encrypt(CryptoHelperEncryptRequest $request)
    {
        $user = $this->requireApiUser($request->headers);

        $plaintext = (string) $request->validated('plaintext');

        try {
            $ciphertext = CryptoService::encryptAes(
                $plaintext,
                $user->getEncryptionKey(),
                $user->getIv(),
            );
        } catch (RuntimeException $e) {
            return JsonResponser::send(
                error: true,
                message: 'Encryption failed.',
                data: ['detail' => $e->getMessage()],
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $envelopeArray = EncryptedPayloadResource::forResponse($ciphertext)->toResponseArray();

        if (JsonResponser::encryptedResponsePreviewEnabled()) {
            try {
                $envelopeArray['preview'] = json_decode($plaintext, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $trimmed = strlen($plaintext) > 1024 ? substr($plaintext, 0, 1024).'…' : $plaintext;
                $envelopeArray['preview'] = [
                    '_note' => 'Plaintext was not valid JSON.',
                    'raw' => $trimmed,
                ];
            }
        }

        return JsonResponser::send(
            error: false,
            message: 'Encrypted.',
            data: [
                'ciphertext' => $ciphertext,
                'response_envelope' => $envelopeArray,
            ],
            statusCode: 200,
        );
    }

    public function encryptJson(Request $request)
    {
        $user = $this->requireApiUser($request->headers);

        try {
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return JsonResponser::send(
                error: true,
                message: 'Request body must be valid JSON.',
                data: null,
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! is_array($payload) || $payload === []) {
            return JsonResponser::send(
                error: true,
                message: 'Request body must be a non-empty JSON object.',
                data: null,
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->encryptPayloadArray($user, $payload);
    }

    public function encryptForm(Request $request)
    {
        $user = $this->requireApiUser($request->headers);

        $payload = $this->scalarFormFields($request);

        if ($payload === []) {
            return JsonResponser::send(
                error: true,
                message: 'Request must include at least one form field.',
                data: null,
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->encryptPayloadArray($user, $payload);
    }

    public function decrypt(CryptoHelperDecryptRequest $request)
    {
        $user = $this->requireApiUser($request->headers);

        $responseField = (string) $request->validated('response');

        try {
            $plaintext = CryptoService::decryptAes(
                $responseField,
                $user->getEncryptionKey(),
                $user->getIv(),
            );
        } catch (RuntimeException $e) {
            return JsonResponser::send(
                error: true,
                message: 'Decryption failed.',
                data: ['detail' => $e->getMessage()],
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return JsonResponser::send(
            error: false,
            message: 'Decrypted.',
            data: [
                'plaintext' => $plaintext,
            ],
            statusCode: 200,
        );
    }

    private function requireApiUser(HeaderBag $headers)
    {
        if (! config('security.api_user_dev_registration.enabled')) {
            abort(403, 'Disabled.');
        }

        $expected = (string) config('security.api_user_dev_registration.secret');
        if ($expected === '') {
            abort(503, 'Registration secret is not configured.');
        }

        $provided = (string) $headers->get(self::DEV_SECRET_HEADER, '');
        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid registration secret.');
        }

        $clientKey = (string) $headers->get(self::CLIENT_KEY_HEADER, '');
        if ($clientKey === '') {
            abort(BaseResponse::HTTP_UNAUTHORIZED, 'Missing client key header.');
        }

        $user = $this->apiUsers->findByClientKey($clientKey);
        if ($user === null) {
            abort(BaseResponse::HTTP_UNAUTHORIZED, 'Invalid or inactive client key.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encryptPayloadArray(ApiUserResource $user, array $payload): \Illuminate\Http\JsonResponse
    {
        try {
            $plaintext = json_encode($payload, flags: JSON_THROW_ON_ERROR);
            $ciphertext = CryptoService::encryptAes(
                $plaintext,
                $user->getEncryptionKey(),
                $user->getIv(),
            );
        } catch (RuntimeException|JsonException $e) {
            return JsonResponser::send(
                error: true,
                message: 'Encryption failed.',
                data: ['detail' => $e->getMessage()],
                statusCode: BaseResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $requestEnvelope = EncryptedPayloadResource::forResponse($ciphertext)->toRequestArray();

        if (JsonResponser::encryptedResponsePreviewEnabled()) {
            $requestEnvelope['preview'] = $payload;
        }

        return JsonResponser::send(
            error: false,
            message: 'Encrypted.',
            data: [
                'ciphertext' => $ciphertext,
                'request_envelope' => $requestEnvelope,
            ],
            statusCode: 200,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function scalarFormFields(Request $request): array
    {
        $payload = [];

        foreach ($request->all() as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
