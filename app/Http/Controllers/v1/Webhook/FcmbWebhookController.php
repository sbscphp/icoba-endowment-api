<?php

namespace App\Http\Controllers\v1\Webhook;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Responser\JsonResponser;
use App\Services\Reconciliation\BankFeedIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stub endpoint for the future FCMB credit-notification webhook.
 *
 * FCMB has not yet finalized the partnership integration. Until they do, this
 * endpoint only accepts traffic when `fcmb_webhook.allow_test_payloads` is
 * enabled, and always returns 501 in production deployments. The same row
 * processor (`BankFeedIngestionService`) used by the CSV importer is reused
 * here so behaviour stays identical between the two ingestion paths.
 */
class FcmbWebhookController extends Controller
{
    public function __construct(
        private readonly BankFeedIngestionService $ingestionService,
    ) {}

    public function handle(Request $request)
    {
        try {
            if (! (bool) config('fcmb_webhook.enabled', false)) {
                if (! (bool) config('fcmb_webhook.allow_test_payloads', false)) {
                    return JsonResponser::send(true, 'FCMB webhook is not yet enabled.', null, 501);
                }
            }

            if (! $this->signatureValid($request)) {
                Log::warning('FCMB webhook: signature verification failed.', [
                    'header' => (string) config('fcmb_webhook.signature_header'),
                ]);

                return JsonResponser::send(true, 'Invalid signature.', null, 401);
            }

            $payload = $request->all();
            $rows = $this->extractRows($payload);

            $summary = $this->ingestionService->ingest($rows, 'fcmb_webhook');

            return JsonResponser::send(false, 'FCMB webhook accepted.', $summary);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Webhook\FcmbWebhookController@handle');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        $path = (string) config('fcmb_webhook.payload_map.transactions_path', 'transactions');
        $fields = (array) config('fcmb_webhook.payload_map.fields', []);

        $rawRows = data_get($payload, $path);
        if (! is_array($rawRows)) {
            $rawRows = [$payload];
        }

        $rows = [];
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'transaction_date' => $row[$fields['transaction_date'] ?? 'transaction_date'] ?? null,
                'amount' => $row[$fields['amount'] ?? 'amount'] ?? null,
                'narration' => $row[$fields['narration'] ?? 'narration'] ?? null,
                'statement_reference' => $row[$fields['statement_reference'] ?? 'reference'] ?? null,
                'account_number' => $row[$fields['account_number'] ?? 'account_number'] ?? null,
                'source' => 'fcmb_webhook',
            ];
        }

        return $rows;
    }

    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('fcmb_webhook.shared_secret', '');
        if ($secret === '') {
            return (bool) config('fcmb_webhook.allow_test_payloads', false);
        }

        $header = (string) config('fcmb_webhook.signature_header', 'X-FCMB-Signature');
        $received = (string) $request->header($header, '');
        if ($received === '') {
            return false;
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals($expected, $received);
    }
}
