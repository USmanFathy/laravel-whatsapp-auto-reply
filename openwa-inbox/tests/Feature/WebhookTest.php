<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_incoming_message_is_persisted_with_its_conversation(): void
    {
        Http::fake();

        $this->webhook('message.received', $this->receivedData('WA1', '628111@c.us', 'Hello there'))
            ->assertOk()->assertJson(['status' => 'stored']);

        $conversation = Conversation::where('chat_id', '628111@c.us')->firstOrFail();
        $this->assertSame('Alice', $conversation->name);
        $this->assertSame('Hello there', $conversation->last_message_preview);
        $this->assertSame(1, $conversation->unread_count);

        $message = Message::where('wa_message_id', 'WA1')->firstOrFail();
        $this->assertSame('incoming', $message->direction);
        $this->assertSame('Hello there', $message->body);
        $this->assertSame('webhook', $message->source);
    }

    public function test_outgoing_message_sent_event_is_stored_as_outgoing(): void
    {
        Http::fake();

        $this->webhook('message.sent', $this->receivedData('WA2', '628111@c.us', 'My reply', ['fromMe' => true, 'from' => '628999@c.us', 'to' => '628111@c.us']))
            ->assertOk();

        $message = Message::where('wa_message_id', 'WA2')->firstOrFail();
        $this->assertSame('outgoing', $message->direction);
        $this->assertSame('628111@c.us', $message->conversation->chat_id);
        $this->assertSame(0, $message->conversation->unread_count);
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_stored(): void
    {
        Http::fake();

        $this->webhook('message.received', $this->receivedData('WA3'), secret: 'some-other-secret-value')->assertStatus(401);
        $this->webhook('message.received', $this->receivedData('WA3'), sign: false)->assertStatus(401);

        $this->assertSame(0, Message::count());
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_tampered_body_fails_signature_check(): void
    {
        $raw = json_encode(['event' => 'message.received', 'data' => $this->receivedData('WA4')]);
        $signature = \App\Support\WebhookSignature::compute($raw, config('openwa.webhook_secret'));

        $this->call('POST', '/api/openwa/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_OPENWA_SIGNATURE' => $signature,
        ], $raw.' ')->assertStatus(401);

        $this->assertSame(0, Message::count());
    }

    public function test_webhook_is_refused_when_no_secret_is_configured(): void
    {
        config(['openwa.webhook_secret' => '']);

        $this->webhook('message.received', $this->receivedData('WA5'), secret: '')->assertStatus(503);
        $this->assertSame(0, Message::count());
    }

    public function test_same_delivery_twice_is_processed_once(): void
    {
        Http::fake();

        $this->webhook('message.received', $this->receivedData('WA6'), 'idem-1')->assertJson(['status' => 'stored']);
        $this->webhook('message.received', $this->receivedData('WA6'), 'idem-1')->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, Message::count());
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, Conversation::first()->unread_count);
    }

    public function test_same_whatsapp_message_with_a_different_idempotency_key_is_not_stored_twice(): void
    {
        Http::fake();

        $this->webhook('message.received', $this->receivedData('WA7'), 'idem-a')->assertJson(['status' => 'stored']);
        $this->webhook('message.received', $this->receivedData('WA7'), 'idem-b')->assertJson(['status' => 'already_stored']);

        $this->assertSame(1, Message::count());
    }

    public function test_ack_edit_and_revoke_events_update_the_stored_message(): void
    {
        Http::fake();
        $this->webhook('message.sent', $this->receivedData('WA8', '628111@c.us', 'Draft', ['fromMe' => true]));

        $this->webhook('message.ack', ['messageId' => 'WA8', 'status' => 'read'])->assertOk();
        $this->assertSame('read', Message::first()->status);

        $this->webhook('message.ack', ['messageId' => 'WA8', 'status' => 'delivered'])->assertOk();
        $this->assertSame('read', Message::first()->status, 'ack must never move backwards');

        $this->webhook('message.edited', ['messageId' => 'WA8', 'body' => 'Final', 'chatId' => '628111@c.us'])->assertOk();
        $this->assertSame('Final', Message::first()->body);
        $this->assertTrue(Message::first()->edited);

        $this->webhook('message.revoked', ['revokedId' => 'WA8'])->assertOk();
        $this->assertSame('revoked', Message::first()->type);
    }

    public function test_status_broadcast_and_unknown_events_are_ignored(): void
    {
        Http::fake();

        $this->webhook('message.received', $this->receivedData('WA9', 'status@broadcast'))->assertJson(['status' => 'ignored']);
        $this->webhook('session.status', ['status' => 'ready'])->assertJson(['status' => 'ignored']);

        $this->assertSame(0, Message::count());
    }

    public function test_malformed_signed_payload_is_a_bad_request(): void
    {
        $raw = '{"not":"an event"}';
        $this->call('POST', '/api/openwa/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => \App\Support\WebhookSignature::compute($raw, config('openwa.webhook_secret')),
        ], $raw)->assertStatus(400);
    }
}
