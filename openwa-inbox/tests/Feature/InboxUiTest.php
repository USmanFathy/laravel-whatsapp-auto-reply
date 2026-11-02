<?php

namespace Tests\Feature;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class InboxUiTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_inbox_lists_conversations_newest_first_and_marks_auto_reply(): void
    {
        $this->conversation('111@c.us', true, 'x', ['name' => 'Older', 'last_message_at' => now()->subDay()]);
        $this->conversation('222@c.us', false, null, ['name' => 'Newer', 'last_message_at' => now()]);

        $this->get(route('inbox'))->assertOk()->assertSeeInOrder(['Newer', 'Older'])->assertSee('auto');
    }

    public function test_opening_a_conversation_shows_messages_and_clears_unread(): void
    {
        Http::fake();
        $this->webhook('message.received', $this->receivedData('U1', '111@c.us', 'Hi from Alice'));
        $conversation = \App\Models\Conversation::first();
        $this->assertSame(1, $conversation->unread_count);

        $this->get(route('conversations.show', $conversation))->assertOk()->assertSee('Hi from Alice');

        $this->assertSame(0, $conversation->refresh()->unread_count);
    }

    public function test_message_text_is_escaped(): void
    {
        $c = $this->conversation('111@c.us');
        Message::create(['conversation_id' => $c->id, 'wa_message_id' => 'X1', 'direction' => 'incoming', 'source' => 'webhook', 'body' => '<script>alert(1)</script>', 'sent_at' => now()]);

        $this->get(route('conversations.show', $c))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_search_filters_the_list(): void
    {
        $this->conversation('111@c.us', false, null, ['name' => 'Alice']);
        $this->conversation('222@c.us', false, null, ['name' => 'Bob']);

        $this->get(route('inbox', ['q' => 'Ali']))->assertSee('Alice')->assertDontSee('Bob');
    }

    public function test_manual_message_is_sent_through_openwa(): void
    {
        $this->fakeSendOk();
        $c = $this->conversation('111@c.us');

        $this->post(route('conversations.send', $c), ['text' => 'Hello manually'])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Hello manually', $this->sentTexts()[0]['text']);
    }

    public function test_manual_message_validation_and_api_failure_are_shown(): void
    {
        $c = $this->conversation('111@c.us');
        $this->post(route('conversations.send', $c), ['text' => ''])->assertSessionHasErrors('text');

        Http::fake(['openwa.test/*' => Http::response(['message' => 'Session is not ready'], 400)]);
        $this->post(route('conversations.send', $c), ['text' => 'hi'])->assertSessionHasErrors('text');
    }

    public function test_session_page_shows_status_and_qr_code(): void
    {
        Http::fake([
            'openwa.test/api/sessions/*/qr' => Http::response(['qrCode' => 'data:image/png;base64,AAAA', 'status' => 'qr_ready']),
            'openwa.test/api/sessions/*' => Http::response(['id' => $this->sessionId, 'status' => 'qr_ready']),
        ]);

        $this->get(route('session'))->assertOk()->assertSee('qr_ready')->assertSee('data:image/png;base64,AAAA', false);
    }

    public function test_session_page_explains_connection_errors(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['message' => 'Invalid API key'], 401)]);

        $this->get(route('session'))->assertOk()->assertSee('HTTP 401');
    }
}
