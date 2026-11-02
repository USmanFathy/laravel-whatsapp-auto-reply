<?php

namespace Tests\Feature;

use App\Models\AutoReplyLog;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class AutoReplyTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_enabled_conversation_receives_exactly_one_reply_through_openwa(): void
    {
        $this->fakeSendOk();
        $this->conversation('628111@c.us', true, 'We will answer soon.');

        $this->webhook('message.received', $this->receivedData('A1', '628111@c.us'))->assertOk();

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertSame('test-api-key', $sent[0]->header('X-API-Key')[0]);
        $this->assertSame(['chatId' => '628111@c.us', 'text' => 'We will answer soon.'], $sent[0]->data());
        $this->assertStringStartsWith('autoreply-', $sent[0]->header('Idempotency-Key')[0]);

        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('true_628111@c.us_OUT1', $log->openwa_message_id);
    }

    public function test_disabled_conversation_records_the_message_but_sends_nothing(): void
    {
        $this->fakeSendOk();
        $this->conversation('628111@c.us', false, 'Unused text');

        $this->webhook('message.received', $this->receivedData('A2', '628111@c.us'))->assertOk();

        $this->assertSame(1, Message::count());
        $this->assertCount(0, $this->sentTexts());
        $this->assertSame(0, AutoReplyLog::count());
    }

    public function test_conversation_without_any_settings_never_replies(): void
    {
        $this->fakeSendOk();

        $this->webhook('message.received', $this->receivedData('A3', '628222@c.us'))->assertOk();

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_each_conversation_uses_its_own_text_and_switch(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Text for one');
        $this->conversation('222@c.us', true, 'Text for two');
        $this->conversation('333@c.us', false, 'Text for three');

        $this->webhook('message.received', $this->receivedData('B1', '111@c.us'));
        $this->webhook('message.received', $this->receivedData('B2', '222@c.us'));
        $this->webhook('message.received', $this->receivedData('B3', '333@c.us'));

        $byChat = collect($this->sentTexts())->mapWithKeys(fn (Request $r) => [$r['chatId'] => $r['text']])->all();
        $this->assertSame(['111@c.us' => 'Text for one', '222@c.us' => 'Text for two'], $byChat);
    }

    public function test_disabling_one_conversation_through_the_form_does_not_disable_the_others(): void
    {
        $this->fakeSendOk();
        $one = $this->conversation('111@c.us', true, 'One');
        $two = $this->conversation('222@c.us', true, 'Two');

        $this->put(route('conversations.auto-reply', $one), ['auto_reply_text' => 'One'])->assertRedirect(); // checkbox unchecked

        $this->assertFalse($one->refresh()->auto_reply_enabled);
        $this->assertTrue($two->refresh()->auto_reply_enabled);

        $this->webhook('message.received', $this->receivedData('C1', '111@c.us'))->assertOk();
        $this->webhook('message.received', $this->receivedData('C2', '222@c.us'))->assertOk();

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertSame('222@c.us', $sent[0]['chatId']);
        $this->assertSame(2, Message::count(), 'both messages are recorded regardless of the switch');
    }

    public function test_a_second_message_is_not_answered_during_the_cooldown(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Hi');

        $this->webhook('message.received', $this->receivedData('D1', '111@c.us'));
        $this->webhook('message.received', $this->receivedData('D2', '111@c.us'));

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame(['sent', 'skipped'], AutoReplyLog::orderBy('id')->pluck('status')->all());
        $this->assertSame('cooldown', AutoReplyLog::orderBy('id')->get()[1]->skip_reason);
    }

    public function test_zero_cooldown_answers_every_message_once(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Hi', ['auto_reply_cooldown_seconds' => 0]);

        $this->webhook('message.received', $this->receivedData('E1', '111@c.us'));
        $this->webhook('message.received', $this->receivedData('E2', '111@c.us'));

        $this->assertCount(2, $this->sentTexts());
    }

    public function test_redelivered_webhooks_never_cause_a_second_reply(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Hi', ['auto_reply_cooldown_seconds' => 0]);

        $this->webhook('message.received', $this->receivedData('F1', '111@c.us'), 'same-key');
        $this->webhook('message.received', $this->receivedData('F1', '111@c.us'), 'same-key');
        $this->webhook('message.received', $this->receivedData('F1', '111@c.us'), 'other-key');

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame(1, AutoReplyLog::count());
    }

    public function test_our_own_outgoing_messages_never_trigger_a_reply(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Hi', ['auto_reply_cooldown_seconds' => 0]);

        $this->webhook('message.sent', $this->receivedData('G1', '111@c.us', 'bot text', ['fromMe' => true]));

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_old_replayed_messages_are_recorded_but_not_answered(): void
    {
        $this->fakeSendOk();
        $this->conversation('111@c.us', true, 'Hi');

        $this->webhook('message.received', $this->receivedData('H1', '111@c.us', 'old', ['timestamp' => time() - 7200]));

        $this->assertSame(1, Message::count());
        $this->assertCount(0, $this->sentTexts());
        $this->assertSame('message_too_old', AutoReplyLog::first()->skip_reason);
    }

    public function test_openwa_server_error_keeps_the_message_and_marks_the_reply_failed(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['message' => 'engine crashed'], 500)]);
        $this->conversation('111@c.us', true, 'Hi');

        $this->webhook('message.received', $this->receivedData('I1', '111@c.us'))->assertOk();

        $this->assertSame(1, Message::count());
        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('HTTP 500', $log->last_error);
    }

    public function test_openwa_unreachable_is_handled_without_losing_the_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));
        $this->conversation('111@c.us', true, 'Hi');

        $this->webhook('message.received', $this->receivedData('I2', '111@c.us'))->assertOk();

        $this->assertSame(1, Message::count());
        $this->assertSame('failed', AutoReplyLog::firstOrFail()->status);
    }

    public function test_client_error_is_not_retried(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['message' => 'Session is not ready'], 400)]);
        $this->conversation('111@c.us', true, 'Hi');

        $this->webhook('message.received', $this->receivedData('I3', '111@c.us'))->assertOk();

        $this->assertCount(1, $this->sentTexts());
        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame(1, $log->attempts);
    }

    public function test_failed_reply_can_be_retried_once_and_never_twice(): void
    {
        Http::fake([
            'openwa.test/*' => Http::sequence()
                ->push(['message' => 'down'], 503)
                ->push(['messageId' => 'OUT9'], 201),
        ]);
        $this->conversation('111@c.us', true, 'Hi');
        $this->webhook('message.received', $this->receivedData('J1', '111@c.us'));
        $this->assertSame('failed', AutoReplyLog::firstOrFail()->status);

        Artisan::call('openwa:retry-replies', ['--failed' => true, '--minutes' => 0]);
        $this->assertSame('sent', AutoReplyLog::first()->status);

        Artisan::call('openwa:retry-replies', ['--failed' => true, '--minutes' => 0]);
        $this->assertCount(2, $this->sentTexts(), 'one failed attempt + one successful attempt, nothing more');
    }

    public function test_retry_button_requeues_a_failed_reply(): void
    {
        Http::fake(['openwa.test/*' => Http::sequence()->push([], 500)->push(['messageId' => 'OUT1'], 201)]);
        $this->conversation('111@c.us', true, 'Hi');
        $this->webhook('message.received', $this->receivedData('J2', '111@c.us'));

        $this->post(route('auto-replies.retry', AutoReplyLog::first()))->assertRedirect();

        $this->assertSame('sent', AutoReplyLog::first()->status);
    }

    public function test_reply_is_skipped_if_the_conversation_was_disabled_before_sending(): void
    {
        $this->fakeSendOk();
        $conversation = $this->conversation('111@c.us', true, 'Hi');
        $message = Message::create(['conversation_id' => $conversation->id, 'wa_message_id' => 'K1', 'direction' => 'incoming', 'source' => 'webhook', 'body' => 'x']);
        $log = AutoReplyLog::create(['conversation_id' => $conversation->id, 'trigger_message_id' => $message->id, 'reply_text' => 'Hi', 'status' => 'pending']);
        $conversation->update(['auto_reply_enabled' => false]);

        \App\Jobs\SendAutoReply::dispatch($log->id);

        $this->assertCount(0, $this->sentTexts());
        $this->assertSame('skipped', $log->refresh()->status);
        $this->assertSame('disabled_before_send', $log->skip_reason);
    }
}
