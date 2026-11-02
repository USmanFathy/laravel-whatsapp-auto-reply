<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookRegistrationTest extends TestCase
{
    public function test_register_creates_a_webhook_for_incoming_messages_only(): void
    {
        Http::fake(['openwa.test/api/sessions/*/webhooks' => Http::sequence()->push([])->push(['id' => 'wh-1'], 201)]);

        $this->assertSame(0, Artisan::call('openwa:register-webhook'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['url'] === config('openwa.webhook_url')
            && $r['secret'] === config('openwa.webhook_secret')
            && $r['events'] === ['message.received']);
    }

    public function test_register_updates_an_existing_webhook_instead_of_duplicating_it(): void
    {
        Http::fake([
            'openwa.test/api/sessions/*/webhooks/wh-9' => Http::response(['id' => 'wh-9']),
            'openwa.test/api/sessions/*/webhooks' => Http::response([['id' => 'wh-9', 'url' => config('openwa.webhook_url')]]),
        ]);

        $this->assertSame(0, Artisan::call('openwa:register-webhook'));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['events'] === ['message.received']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_register_refuses_a_short_secret(): void
    {
        config(['openwa.webhook_secret' => 'short']);
        Http::fake();

        $this->assertSame(1, Artisan::call('openwa:register-webhook'));
        Http::assertNothingSent();
    }
}
