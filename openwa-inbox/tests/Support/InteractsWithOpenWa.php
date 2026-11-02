<?php

namespace Tests\Support;

use App\Models\AutoReplySetting;
use App\Support\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait InteractsWithOpenWa
{
    /** A real message.received `data` object (fields of OpenWA's IncomingMessage). */
    protected function incoming(string $waId, string $chatId = '628111@c.us', string $body = 'Hello', array $extra = []): array
    {
        return array_merge([
            'id' => $waId,
            'from' => $chatId,
            'to' => '628999@c.us',
            'chatId' => $chatId,
            'body' => $body,
            'type' => 'text',
            'timestamp' => time(),
            'fromMe' => false,
            'isGroup' => false,
            'kind' => 'individual',
        ], $extra);
    }

    /** Posts a webhook exactly like OpenWA: raw JSON body + HMAC header + idempotency header. */
    protected function webhook(array $data, string $event = 'message.received', ?string $key = null, ?string $secret = null, bool $sign = true): TestResponse
    {
        $raw = json_encode(['event' => $event, 'sessionId' => 'inbox', 'timestamp' => now()->toIso8601String(), 'data' => $data]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_OPENWA_IDEMPOTENCY_KEY' => $key ?? 'k-'.md5($raw.microtime(true).random_int(0, PHP_INT_MAX))];
        if ($sign) {
            $server['HTTP_X_OPENWA_SIGNATURE'] = WebhookSignature::compute($raw, $secret ?? config('openwa.webhook_secret'));
        }

        return $this->call('POST', '/api/openwa/webhook', [], [], [], $server, $raw);
    }

    protected function enableAutoReply(string $message = 'Thanks! We will reply soon.'): AutoReplySetting
    {
        $setting = AutoReplySetting::current();
        $setting->update(['enabled' => true, 'message' => $message]);

        return $setting;
    }

    protected function asAdmin(): static
    {
        return $this->withServerVariables(['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test-admin-pass']);
    }

    protected function fakeSendOk(): void
    {
        Http::fake(['openwa.test/api/sessions/*/messages/send-text' => Http::response(['messageId' => 'true_out_1', 'timestamp' => time()], 201)]);
    }

    /** @return list<Request> every send-text request made so far */
    protected function sentTexts(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages/send-text'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    /** Runs exactly one job through the real queue worker code path. */
    protected function workOnce(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
    }
}
