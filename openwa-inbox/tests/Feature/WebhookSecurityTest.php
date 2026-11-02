<?php

namespace Tests\Feature;

use App\Models\AutoReplyLog;
use App\Models\WebhookEvent;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_a_wrong_or_missing_signature_is_rejected_and_nothing_happens(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('A1'), secret: 'some-other-secret-value')->assertStatus(401);
        $this->webhook($this->incoming('A2'), sign: false)->assertStatus(401);

        $this->assertCount(0, $this->sentTexts());
        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(0, AutoReplyLog::count());
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        $raw = json_encode(['event' => 'message.received', 'data' => $this->incoming('A3')]);
        $signature = WebhookSignature::compute($raw, config('openwa.webhook_secret'));

        $this->call('POST', '/api/openwa/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_OPENWA_SIGNATURE' => $signature], $raw.' ')->assertStatus(401);
    }

    public function test_the_webhook_is_refused_while_no_secret_is_configured(): void
    {
        config(['openwa.webhook_secret' => '']);

        $this->webhook($this->incoming('A4'), secret: '')->assertStatus(503);
    }

    public function test_a_correctly_signed_body_that_is_not_an_event_is_a_bad_request(): void
    {
        $raw = '{"hello":"world"}';
        $this->call('POST', '/api/openwa/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => WebhookSignature::compute($raw, config('openwa.webhook_secret')),
        ], $raw)->assertStatus(400);
    }

    public function test_every_outcome_answers_with_http_200_so_openwa_never_retries_needlessly(): void
    {
        Http::fake();
        foreach ([
            ['message.received', $this->incoming('B1')],                        // disabled
            ['message.received', $this->incoming('B2', '628111@c.us', 'x', ['fromMe' => true])],
            ['message.sent', $this->incoming('B3')],
            ['session.status', ['status' => 'ready']],
            ['message.received', ['garbage' => true]],
        ] as [$event, $data]) {
            $this->webhook($data, $event)->assertOk();
        }
    }

    public function test_the_secret_is_a_real_openwa_signature_format(): void
    {
        $this->assertSame('sha256='.hash_hmac('sha256', 'body', 'k'), WebhookSignature::compute('body', 'k'));
    }
}
