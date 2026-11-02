<?php

namespace App\Services\OpenWa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the real OpenWA REST API (X-API-Key authentication).
 * Endpoints used: POST send-text and the webhook registration endpoints.
 */
class OpenWaClient
{
    public function sessionId(): string
    {
        $id = (string) config('openwa.session_id');
        if ($id === '') {
            throw new OpenWaException('OPENWA_SESSION_ID is not configured.');
        }

        return $id;
    }

    private function sessionPath(string $suffix = ''): string
    {
        return '/api/sessions/'.rawurlencode($this->sessionId()).$suffix;
    }

    /**
     * $retries overrides the in-call retry count; the queue job passes 0 so that a failing
     * OpenWA never keeps a queue worker busy for longer than one HTTP timeout.
     */
    public function sendText(string $chatId, string $text, string $idempotencyKey, ?int $retries = null): array
    {
        return $this->request('POST', $this->sessionPath('/messages/send-text'), [
            'json' => ['chatId' => $chatId, 'text' => $text],
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'retries' => $retries,
        ]);
    }

    public function webhooks(): array
    {
        return $this->request('GET', $this->sessionPath('/webhooks'));
    }

    public function createWebhook(array $body): array
    {
        return $this->request('POST', $this->sessionPath('/webhooks'), ['json' => $body]);
    }

    public function updateWebhook(string $webhookId, array $body): array
    {
        return $this->request('PUT', $this->sessionPath('/webhooks/'.rawurlencode($webhookId)), ['json' => $body]);
    }

    private function http(): PendingRequest
    {
        $key = (string) config('openwa.api_key');
        if ($key === '') {
            throw new OpenWaException('OPENWA_API_KEY is not configured.');
        }

        return Http::baseUrl(config('openwa.base_url'))
            ->withHeaders(['X-API-Key' => $key])
            ->acceptJson()
            ->timeout((int) config('openwa.timeout'));
    }

    /**
     * @param  array{query?:array,json?:array,headers?:array,retries?:?int}  $options
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $maxAttempts = max(0, $options['retries'] ?? (int) config('openwa.http_retries')) + 1;
        $delayMs = max(0, (int) config('openwa.http_retry_delay_ms'));

        for ($attempt = 1; ; $attempt++) {
            try {
                $pending = $this->http()->withHeaders($options['headers'] ?? []);
                $guzzle = [];
                if (isset($options['query'])) {
                    $guzzle['query'] = $options['query'];
                }
                if (isset($options['json'])) {
                    $guzzle['json'] = $options['json'];
                }
                /** @var Response $response */
                $response = $pending->send($method, $path, $guzzle);
            } catch (ConnectionException $e) {
                if ($attempt < $maxAttempts) {
                    $this->pause($delayMs, $attempt);

                    continue;
                }
                throw new OpenWaException('Cannot reach OpenWA: '.$e->getMessage(), null, true);
            }

            if ($response->successful()) {
                $json = $response->json();

                return is_array($json) ? $json : [];
            }

            $status = $response->status();
            // 409 is what OpenWA answers while WhatsApp Web is reloading / re-injecting: transient, so retryable.
            $retryable = in_array($status, [408, 409, 425, 429], true) || ($status >= 500 && $status !== 501);
            if ($retryable && $attempt < $maxAttempts) {
                $this->pause($delayMs, $attempt);

                continue;
            }

            throw new OpenWaException($this->errorMessage($response), $status, $retryable);
        }
    }

    private function pause(int $delayMs, int $attempt): void
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1000 * $attempt);
        }
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');
        if (is_array($message)) {
            $message = implode('; ', array_map('strval', $message));
        }
        if (! is_string($message) || $message === '') {
            $message = mb_substr(trim($response->body()), 0, 200) ?: 'empty response';
        }

        return 'OpenWA returned HTTP '.$response->status().': '.$message;
    }
}
