<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developer\CryptoHelperDecryptRequest;
use App\Http\Requests\Developer\CryptoHelperEncryptRequest;
use App\Http\Resources\Developer\EncryptedPayloadResource;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Responser\JsonResponser;
use App\Services\CryptoService;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Local/dev helpers: encrypt plaintext or decrypt an API response envelope using a consumer's keys.
 *
 * Guarded by the same flags and secret as {@see ApiUserRegistrationController}.
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
            } catch (\JsonException) {
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
}
