<?php

namespace Tests\Feature;

use App\Jobs\SendAutoReply;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class GlobalAutoReplyTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_an_incoming_message_gets_the_global_reply_through_openwa(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply('Hello from the shop');

        $this->webhook($this->incoming('M1', '628111@c.us'))->assertOk()->assertJson(['status' => 'queued']);

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertSame(['chatId' => '628111@c.us', 'text' => 'Hello from the shop'], $sent[0]->data());
        $this->assertSame(['test-api-key'], $sent[0]->header('X-API-Key'));
        $this->assertStringStartsWith('autoreply-', $sent[0]->header('Idempotency-Key')[0]);
        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
    }

    public function test_every_number_receives_exactly_the_same_reply(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply('Same text for all');

        foreach (['111@c.us', '222@c.us', '333@lid'] as $i => $chat) {
            $this->webhook($this->incoming("A$i", $chat))->assertJson(['status' => 'queued']);
        }

        $byChat = collect($this->sentTexts())->mapWithKeys(fn ($r) => [$r['chatId'] => $r['text']])->all();
        $this->assertSame(['111@c.us' => 'Same text for all', '222@c.us' => 'Same text for all', '333@lid' => 'Same text for all'], $byChat);
    }

    public function test_consecutive_messages_from_one_number_are_each_answered_without_delay(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        foreach (range(1, 5) as $i) {
            $this->webhook($this->incoming("C$i", '628111@c.us', "message $i"))->assertJson(['status' => 'queued']);
        }

        $this->assertCount(5, $this->sentTexts(), 'no cooldown may swallow the 2nd..5th message');
        $this->assertSame(5, AutoReplyLog::where('status', 'sent')->count());
    }

    public function test_nothing_is_sent_while_auto_reply_is_disabled_and_the_reason_is_recorded(): void
    {
        $this->fakeSendOk();
        AutoReplySetting::current()->update(['enabled' => false, 'message' => 'Unused']);

        $this->webhook($this->incoming('D1'))->assertOk()->assertJson(['status' => 'skipped:disabled']);

        $this->assertCount(0, $this->sentTexts());
        $this->assertSame('skipped:disabled', WebhookEvent::firstOrFail()->outcome);
    }

    public function test_enabled_with_empty_message_sends_nothing(): void
    {
        $this->fakeSendOk();
        AutoReplySetting::current()->update(['enabled' => true, 'message' => '  ']);

        $this->webhook($this->incoming('D2'))->assertJson(['status' => 'skipped:no_reply_text']);

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_a_changed_message_is_used_by_the_very_next_reply(): void
    {
        $this->fakeSendOk();
        $setting = $this->enableAutoReply('First text');
        $this->webhook($this->incoming('E1'));

        $setting->update(['message' => 'Second text']);
        $this->webhook($this->incoming('E2'));

        $this->assertSame(['First text', 'Second text'], collect($this->sentTexts())->map(fn ($r) => $r['text'])->all());
    }

    public function test_outgoing_messages_never_trigger_a_reply(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        // The automatic reply echoed back by OpenWA, or a message typed on the phone.
        $this->webhook($this->incoming('F1', '628111@c.us', 'Thanks!', ['fromMe' => true]))->assertJson(['status' => 'ignored:outgoing']);
        $this->webhook($this->incoming('F2', '628111@c.us', 'Thanks!'), 'message.sent')->assertJson(['status' => 'ignored:event']);

        $this->assertCount(0, $this->sentTexts());
        $this->assertSame(0, AutoReplyLog::count());
    }

    public function test_the_reply_to_a_customer_does_not_start_a_loop(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('G1', '628111@c.us'));
        // OpenWA now reports the message we sent, flagged fromMe:
        $this->webhook($this->incoming('OUT1', '628111@c.us', 'Thanks! We will reply soon.', ['fromMe' => true, 'from' => '628999@c.us', 'to' => '628111@c.us']));

        $this->assertCount(1, $this->sentTexts());
    }

    public function test_groups_status_channels_and_system_messages_are_ignored(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $cases = [
            ['ignored:group', $this->incoming('H1', '1203@g.us', 'hi', ['isGroup' => true, 'kind' => 'group'])],
            ['ignored:broadcast', $this->incoming('H2', 'status@broadcast', 'story', ['kind' => 'status', 'isStatusBroadcast' => true])],
            ['ignored:channel', $this->incoming('H3', '120@newsletter', 'news', ['kind' => 'channel'])],
            ['ignored:type', $this->incoming('H4', '628111@c.us', '', ['type' => 'revoked'])],
            ['ignored:type', $this->incoming('H5', '628111@c.us', '', ['type' => 'call'])],
        ];
        foreach ($cases as [$expected, $data]) {
            $this->webhook($data)->assertJson(['status' => $expected]);
        }

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_media_messages_without_text_are_answered(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('I1', '628111@c.us', '', ['type' => 'image']))->assertJson(['status' => 'queued']);

        $this->assertCount(1, $this->sentTexts());
    }

    public function test_old_messages_are_not_answered_and_the_reason_is_recorded(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('J1', '628111@c.us', 'old', ['timestamp' => time() - 7200]))->assertJson(['status' => 'skipped:too_old']);

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_the_same_delivery_twice_produces_one_reply(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('K1'), key: 'same')->assertJson(['status' => 'queued']);
        $this->webhook($this->incoming('K1'), key: 'same')->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_the_same_whatsapp_message_under_another_idempotency_key_produces_one_reply(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('L1'), key: 'a')->assertJson(['status' => 'queued']);
        $this->webhook($this->incoming('L1'), key: 'b')->assertJson(['status' => 'duplicate_message']);

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame(1, AutoReplyLog::count());
    }

    public function test_a_rapid_flood_from_one_number_is_capped_but_other_numbers_are_unaffected(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();

        foreach (range(1, 6) as $i) {
            $this->webhook($this->incoming("N$i", '628111@c.us'))->assertJson(['status' => 'queued']);
        }
        $this->webhook($this->incoming('N7', '628111@c.us'))->assertJson(['status' => 'skipped:sender_rate_limited']);
        $this->webhook($this->incoming('X1', '628222@c.us'))->assertJson(['status' => 'queued']);

        $this->assertCount(7, $this->sentTexts());
    }

    public function test_the_rate_limit_window_is_one_minute_not_a_long_cooldown(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();
        foreach (range(1, 6) as $i) {
            $this->webhook($this->incoming("O$i", '628111@c.us'));
        }

        $this->travel(61)->seconds();

        $this->webhook($this->incoming('O7', '628111@c.us', 'hi', ['timestamp' => time()]))->assertJson(['status' => 'queued']);
    }

    public function test_the_webhook_never_calls_openwa_itself_it_only_queues_the_reply(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeSendOk();
        $this->enableAutoReply();

        $this->webhook($this->incoming('P1'))->assertOk()->assertJson(['status' => 'queued']);

        Http::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('pending', AutoReplyLog::firstOrFail()->status);

        $this->workOnce();

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_reply_is_skipped_if_auto_reply_was_switched_off_while_it_waited_in_the_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeSendOk();
        $setting = $this->enableAutoReply();
        $this->webhook($this->incoming('Q1'));

        $setting->update(['enabled' => false]);
        $this->workOnce();

        $this->assertCount(0, $this->sentTexts());
        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('skipped', $log->status);
        $this->assertSame('disabled_before_send', $log->skip_reason);
    }

    public function test_the_job_never_sends_twice_for_the_same_log(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();
        $this->webhook($this->incoming('R1'));

        SendAutoReply::dispatchSync(AutoReplyLog::firstOrFail()->id);
        SendAutoReply::dispatchSync(AutoReplyLog::firstOrFail()->id);

        $this->assertCount(1, $this->sentTexts());
    }
}
