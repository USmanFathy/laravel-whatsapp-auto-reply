<?php

namespace Tests\Unit;

use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenWaClientTest extends TestCase
{
    public function test_transient_errors_are_retried_inside_one_call_when_retries_are_allowed(): void
    {
        config(['openwa.http_retries' => 2]);
        Http::fake(['openwa.test/*' => Http::sequence()->push([], 502)->push([], 429)->push(['messageId' => 'M1'], 201)]);

        $this->assertSame('M1', app(OpenWaClient::class)->sendText('1@c.us', 'x', 'k')['messageId']);
        Http::assertSentCount(3);
    }

    public function test_the_queue_job_style_call_makes_exactly_one_request(): void
    {
        config(['openwa.http_retries' => 5]);
        Http::fake(['openwa.test/*' => Http::response([], 503)]);

        try {
            app(OpenWaClient::class)->sendText('1@c.us', 'x', 'k', retries: 0);
            $this->fail('exception expected');
        } catch (OpenWaException $e) {
            $this->assertTrue($e->retryable);
        }
        Http::assertSentCount(1);
    }

    public function test_409_is_retryable_and_400_501_are_not(): void
    {
        foreach ([[409, true], [408, true], [429, true], [500, true], [400, false], [401, false], [404, false], [501, false]] as [$status, $retryable]) {
            Http::fake(['openwa.test/*' => Http::response(['message' => 'x'], $status)]);
            try {
                app(OpenWaClient::class)->sendText('1@c.us', 'x', 'k', retries: 0);
                $this->fail("HTTP $status should throw");
            } catch (OpenWaException $e) {
                $this->assertSame($retryable, $e->retryable, "HTTP $status");
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function test_validation_messages_from_openwa_are_readable(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['message' => ['chatId must be a string', 'text should not be empty']], 400)]);

        try {
            app(OpenWaClient::class)->sendText('1@c.us', 'x', 'k');
            $this->fail('exception expected');
        } catch (OpenWaException $e) {
            $this->assertStringContainsString('chatId must be a string; text should not be empty', $e->getMessage());
        }
    }

    public function test_request_shape_matches_the_real_openwa_send_text_endpoint(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['messageId' => 'M'], 201)]);

        app(OpenWaClient::class)->sendText('628111@c.us', 'hello', 'idem-1');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'http://openwa.test/api/sessions/0a941dac-a965-45e7-b318-74ae8be134f0/messages/send-text'
            && $r->header('X-API-Key') === ['test-api-key']
            && $r->header('Idempotency-Key') === ['idem-1']
            && $r->data() === ['chatId' => '628111@c.us', 'text' => 'hello']);
    }

    public function test_missing_credentials_fail_before_any_request(): void
    {
        config(['openwa.api_key' => '']);
        Http::fake();

        try {
            app(OpenWaClient::class)->sendText('1@c.us', 'x', 'k');
            $this->fail('exception expected');
        } catch (OpenWaException $e) {
            $this->assertStringContainsString('OPENWA_API_KEY', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
