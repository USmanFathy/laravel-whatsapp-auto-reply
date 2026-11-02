<?php

namespace Tests\Feature;

use App\Models\AutoReplyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class FailureRecoveryTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_a_temporary_openwa_error_is_retried_by_the_queue_and_then_succeeds(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['openwa.test/*' => Http::sequence()->push(['message' => 'engine busy'], 503)->push(['messageId' => 'OUT1'], 201)]);
        $this->enableAutoReply();
        $this->webhook($this->incoming('S1', '628111@c.us'))->assertOk();

        $this->workOnce(); // attempt 1 fails -> released with a short backoff

        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('pending', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertStringContainsString('HTTP 503', $log->last_error);
        $this->assertSame(1, DB::table('jobs')->count(), 'the job is still waiting for its retry');

        $this->travel(10)->seconds();
        $this->workOnce(); // attempt 2

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertSame(2, $log->attempts);
        $this->assertNull($log->last_error);
        $this->assertCount(2, $this->sentTexts());
        $this->assertSame(
            $this->sentTexts()[0]->header('Idempotency-Key'),
            $this->sentTexts()[1]->header('Idempotency-Key'),
            'a retry must reuse the idempotency key so a lost response cannot double-send',
        );
    }

    public function test_409_while_whatsapp_web_is_reloading_is_treated_as_temporary(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['openwa.test/*' => Http::sequence()
            ->push(['message' => 'WhatsApp Web is reloading its page and the session is re-injecting. Retry in a few seconds.'], 409)
            ->push(['messageId' => 'OUT1'], 201)]);
        $this->enableAutoReply();
        $this->webhook($this->incoming('S2'));

        $this->workOnce();
        $this->assertSame('pending', AutoReplyLog::firstOrFail()->status);

        $this->travel(10)->seconds();
        $this->workOnce();
        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
    }

    public function test_a_job_waiting_for_its_retry_does_not_block_replies_to_other_numbers(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(function (Request $request) {
            return $request['chatId'] === '111@c.us'
                ? Http::response(['message' => 'down'], 503)
                : Http::response(['messageId' => 'OUT'], 201);
        });
        $this->enableAutoReply();
        $this->webhook($this->incoming('T1', '111@c.us'));
        $this->webhook($this->incoming('T2', '222@c.us'));
        $this->webhook($this->incoming('T3', '333@c.us'));

        $this->workOnce(); // 111 fails and is delayed
        $this->workOnce(); // 222 is processed immediately
        $this->workOnce(); // 333 is processed immediately

        $status = AutoReplyLog::pluck('status', 'chat_id')->all();
        $this->assertSame(['111@c.us' => 'pending', '222@c.us' => 'sent', '333@c.us' => 'sent'], $status);
    }

    public function test_a_failed_reply_does_not_block_the_next_message_from_the_same_number(): void
    {
        Http::fake(['openwa.test/*' => Http::sequence()->push(['message' => 'Session is not ready'], 400)->push(['messageId' => 'OUT2'], 201)]);
        $this->enableAutoReply();

        $this->webhook($this->incoming('U1', '628111@c.us'))->assertOk();
        $this->assertSame('failed', AutoReplyLog::where('wa_message_id', 'U1')->firstOrFail()->status);

        $this->webhook($this->incoming('U2', '628111@c.us'))->assertOk();

        $this->assertSame('sent', AutoReplyLog::where('wa_message_id', 'U2')->firstOrFail()->status);
    }

    public function test_unreachable_openwa_keeps_the_webhook_fast_and_the_reply_recoverable(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));
        $this->enableAutoReply();

        $this->webhook($this->incoming('V1'))->assertOk()->assertJson(['status' => 'queued']);

        $log = AutoReplyLog::firstOrFail();
        $this->assertSame('failed', $log->status); // sync queue driver: no retry schedule
        $this->assertStringContainsString('Cannot reach OpenWA', $log->last_error);

        $this->fakeSendOk();
        Artisan::call('openwa:retry-replies', ['--failed' => true, '--minutes' => 0]);
        $this->assertSame('sent', $log->refresh()->status);
    }

    public function test_retrying_never_double_sends(): void
    {
        Http::fake(['openwa.test/*' => Http::sequence()->push([], 500)->push(['messageId' => 'OUT'], 201)]);
        $this->enableAutoReply();
        $this->webhook($this->incoming('W1'));

        Artisan::call('openwa:retry-replies', ['--failed' => true, '--minutes' => 0]);
        Artisan::call('openwa:retry-replies', ['--failed' => true, '--minutes' => 0]);

        $this->assertCount(2, $this->sentTexts(), 'one failed attempt + one successful attempt, nothing more');
        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
    }

    public function test_a_pending_reply_lost_before_queueing_is_recovered(): void
    {
        $this->fakeSendOk();
        $this->enableAutoReply();
        AutoReplyLog::create(['wa_message_id' => 'X9', 'chat_id' => '628111@c.us', 'status' => 'pending']);

        Artisan::call('openwa:retry-replies', ['--minutes' => 0]);

        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
    }

    public function test_the_retry_button_requeues_failed_replies(): void
    {
        Http::fake(['openwa.test/*' => Http::sequence()->push([], 400)->push(['messageId' => 'OUT'], 201)]);
        $this->enableAutoReply();
        $this->webhook($this->incoming('Y1'));
        $this->assertSame('failed', AutoReplyLog::firstOrFail()->status);

        $this->asAdmin()->post(route('auto-reply.retry-failed'))->assertRedirect(route('auto-reply'));

        $this->assertSame('sent', AutoReplyLog::firstOrFail()->status);
    }
}
