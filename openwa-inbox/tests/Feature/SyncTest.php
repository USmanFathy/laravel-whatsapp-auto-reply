<?php

namespace Tests\Feature;

use App\Models\AutoReplyLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    private function stored(string $id, string $chat, string $body, string $direction = 'incoming'): array
    {
        return ['id' => 'uuid-'.$id, 'sessionId' => $this->sessionId, 'waMessageId' => $id, 'chatId' => $chat, 'from' => $chat, 'to' => '628999@c.us',
            'body' => $body, 'type' => 'text', 'direction' => $direction, 'timestamp' => 1760000000 + (int) preg_replace('/\D/', '', $id), 'status' => 'delivered'];
    }

    private function fakeGateway(array $chats, array $messagesByChat, array $historyByChat = [], int $historyStatus = 200): void
    {
        Http::fake(function (Request $request) use ($chats, $messagesByChat, $historyByChat, $historyStatus) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            if (str_ends_with($path, '/chats')) {
                return Http::response(array_slice($chats, (int) ($q['offset'] ?? 0), (int) $q['limit']));
            }
            if (str_ends_with($path, '/history')) {
                return $historyStatus === 200
                    ? Http::response($historyByChat[urldecode(explode('/', $path)[5])] ?? [])
                    : Http::response(['message' => 'History not supported by this engine'], $historyStatus);
            }
            if (str_ends_with($path, '/messages')) {
                $rows = $messagesByChat[$q['chatId']] ?? [];
                if (isset($q['after'])) {
                    $index = array_search($q['after'], array_column($rows, 'id'), true);
                    $rows = array_slice($rows, $index === false ? count($rows) : $index + 1);
                }

                return Http::response(array_slice($rows, 0, (int) $q['limit']));
            }

            return Http::response([], 404);
        });
    }

    public function test_sync_imports_existing_conversations_and_messages(): void
    {
        $this->fakeGateway(
            [
                ['id' => '111@c.us', 'name' => 'Alice', 'isGroup' => false, 'timestamp' => 1760000500, 'lastMessage' => ['body' => 'Latest']],
                ['id' => '222@g.us', 'name' => 'Family', 'isGroup' => true, 'timestamp' => 1760000400],
                ['id' => 'status@broadcast', 'name' => 'Status'],
            ],
            ['111@c.us' => [$this->stored('3', '111@c.us', 'third'), $this->stored('2', '111@c.us', 'second', 'outgoing'), $this->stored('1', '111@c.us', 'first')]],
        );

        $result = app(SyncService::class)->run();

        $this->assertSame(2, $result['chats']);
        $this->assertSame(3, $result['messages_new']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['111@c.us', '222@g.us'], Conversation::orderBy('chat_id')->pluck('chat_id')->all());
        $this->assertSame('Alice', Conversation::where('chat_id', '111@c.us')->first()->name);
        $this->assertTrue(Conversation::where('chat_id', '222@g.us')->first()->is_group);
        $this->assertSame(['incoming', 'outgoing', 'incoming'], Message::orderBy('wa_message_id')->pluck('direction')->all());
        $this->assertSame('sync', Message::first()->source);
    }

    public function test_running_sync_twice_creates_no_duplicates(): void
    {
        $this->fakeGateway([['id' => '111@c.us', 'name' => 'Alice']], ['111@c.us' => [$this->stored('1', '111@c.us', 'hello')]]);

        app(SyncService::class)->run();
        $second = app(SyncService::class)->run();

        $this->assertSame(1, Message::count());
        $this->assertSame(0, $second['messages_new']);
        $this->assertSame(1, $second['messages_seen']);
    }

    public function test_sync_and_webhook_do_not_duplicate_the_same_message(): void
    {
        Http::fake();
        $this->webhook('message.received', $this->receivedData('WA77', '111@c.us', 'hello'))->assertOk();

        $this->fakeGateway([['id' => '111@c.us']], ['111@c.us' => [$this->stored('WA77', '111@c.us', 'hello')]]);
        app(SyncService::class)->run();

        $this->assertSame(1, Message::count());
    }

    public function test_sync_pages_through_all_stored_messages(): void
    {
        config(['openwa.sync.message_page_size' => 2]);
        $rows = [];
        foreach (range(5, 1) as $i) {
            $rows[] = $this->stored((string) $i, '111@c.us', "m$i");
        }
        $this->fakeGateway([['id' => '111@c.us']], ['111@c.us' => $rows]);

        $result = app(SyncService::class)->run();

        $this->assertSame(5, $result['messages_new']);
        $this->assertSame(5, Message::count());
    }

    public function test_sync_never_sends_automatic_replies(): void
    {
        $this->conversation('111@c.us', true, 'Auto text', ['auto_reply_cooldown_seconds' => 0]);
        $this->fakeGateway([['id' => '111@c.us']], ['111@c.us' => [$this->stored('1', '111@c.us', 'historic')]]);

        app(SyncService::class)->run();

        $this->assertCount(0, $this->sentTexts());
        $this->assertSame(0, AutoReplyLog::count());
    }

    public function test_history_is_imported_when_the_engine_supports_it(): void
    {
        $this->fakeGateway(
            [['id' => '111@c.us']],
            [],
            ['111@c.us' => [['id' => 'true_111@c.us_H1', 'fromMe' => false, 'body' => 'from phone', 'type' => 'chat', 'timestamp' => 1759000000, 'from' => '111@c.us', 'to' => '628999@c.us']]],
        );

        $result = app(SyncService::class)->run(withHistory: true);

        $this->assertTrue($result['history_supported']);
        $this->assertSame('history', Message::where('wa_message_id', 'true_111@c.us_H1')->firstOrFail()->source);
    }

    public function test_history_unsupported_engine_is_reported_not_faked(): void
    {
        $this->fakeGateway([['id' => '111@c.us']], ['111@c.us' => [$this->stored('1', '111@c.us', 'stored')]], [], 501);

        $result = app(SyncService::class)->run(withHistory: true);

        $this->assertFalse($result['history_supported']);
        $this->assertSame(1, $result['messages_new'], 'stored messages are still imported');
        $this->assertSame([], $result['errors']);
    }

    public function test_one_failing_chat_does_not_abort_the_rest(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (str_ends_with($path, '/chats')) {
                return Http::response([['id' => '111@c.us'], ['id' => '222@c.us']]);
            }

            return ($q['chatId'] ?? '') === '111@c.us'
                ? Http::response(['message' => 'boom'], 500)
                : Http::response([$this->stored('9', '222@c.us', 'ok')]);
        });

        $result = app(SyncService::class)->run();

        $this->assertCount(1, $result['errors']);
        $this->assertSame(1, Message::count());
    }

    public function test_chat_list_failure_fails_the_command_cleanly(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->assertSame(1, Artisan::call('openwa:sync'));
        $this->assertStringContainsString('HTTP 401', Artisan::output());
    }

    public function test_sync_button_runs_a_sync_and_reports_the_result(): void
    {
        $this->fakeGateway([['id' => '111@c.us', 'name' => 'Alice']], ['111@c.us' => [$this->stored('1', '111@c.us', 'hello')]]);

        $this->post(route('sync'))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, Message::count());
    }
}
