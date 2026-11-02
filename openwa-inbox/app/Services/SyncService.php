<?php

namespace App\Services;

use App\Models\Conversation;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;

class SyncService
{
    public function __construct(
        private OpenWaClient $client,
        private MessageRecorder $recorder,
    ) {}

    /**
     * Pulls chats and messages from OpenWA. Never triggers automatic replies.
     *
     * - Chats: GET /chats (everything WhatsApp lists for the session).
     * - Messages: GET /messages?chatId=... (what OpenWA itself has stored - i.e. since the gateway first saw them).
     * - $withHistory: additionally GET /messages/{chatId}/history (live WhatsApp Web history). This only works with
     *   the whatsapp-web.js engine; on other engines OpenWA answers 501 and it is reported as unsupported.
     *
     * @return array{chats:int,messages_new:int,messages_seen:int,history_supported:?bool,errors:list<string>}
     */
    public function run(bool $withHistory = false, ?int $chatLimit = null): array
    {
        $stats = ['chats' => 0, 'messages_new' => 0, 'messages_seen' => 0, 'history_supported' => null, 'errors' => []];
        $chatPage = (int) config('openwa.sync.chat_page_size');

        $chats = [];
        for ($offset = 0; ; $offset += $chatPage) {
            $page = $this->client->chats($chatPage, $offset); // failure here aborts the run (nothing to sync)
            foreach ($page as $chat) {
                if (is_array($chat)) {
                    $chats[] = $chat;
                }
            }
            if (count($page) < $chatPage || ($chatLimit !== null && count($chats) >= $chatLimit)) {
                break;
            }
        }
        if ($chatLimit !== null) {
            $chats = array_slice($chats, 0, $chatLimit);
        }

        $historyUnsupported = false;
        foreach ($chats as $chat) {
            $chatId = $chat['id'] ?? null;
            if (! is_string($chatId) || PayloadNormalizer::isIgnoredChat($chatId, $chat['kind'] ?? null)) {
                continue;
            }

            $conversation = $this->upsertChat($chat);
            $stats['chats']++;

            try {
                $this->syncStoredMessages($conversation, $stats);
                if ($withHistory && ! $historyUnsupported) {
                    $this->syncHistory($conversation, $stats, $historyUnsupported);
                }
            } catch (OpenWaException $e) {
                $stats['errors'][] = $conversation->displayName().': '.$e->getMessage();
            }
        }

        if ($withHistory) {
            $stats['history_supported'] = ! $historyUnsupported;
        }

        return $stats;
    }

    private function upsertChat(array $chat): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['chat_id' => $chat['id']],
            ['is_group' => (bool) ($chat['isGroup'] ?? str_ends_with($chat['id'], '@g.us')), 'kind' => $chat['kind'] ?? null],
        );

        $updates = [];
        if (is_string($chat['name'] ?? null) && $chat['name'] !== '' && $conversation->name !== $chat['name']) {
            $updates['name'] = $chat['name'];
        }
        $ts = PayloadNormalizer::time($chat['timestamp'] ?? null);
        if ($ts && (! $conversation->last_message_at || $ts->gt($conversation->last_message_at))) {
            $updates['last_message_at'] = $ts;
            $last = $chat['lastMessage'] ?? null;
            $preview = is_array($last) ? ($last['body'] ?? null) : (is_string($last) ? $last : null);
            if (is_string($preview) && $preview !== '') {
                $updates['last_message_preview'] = mb_substr($preview, 0, 250);
            }
        }
        if ($updates) {
            $conversation->update($updates);
        }

        return $conversation;
    }

    private function syncStoredMessages(Conversation $conversation, array &$stats): void
    {
        $size = (int) config('openwa.sync.message_page_size');
        $after = null;

        for ($page = 0; $page < (int) config('openwa.sync.max_pages_per_chat'); $page++) {
            $rows = $this->client->messages($conversation->chat_id, $size, $after);
            foreach ($rows as $row) {
                $n = is_array($row) ? PayloadNormalizer::fromStored($row) : null;
                if ($n === null) {
                    continue;
                }
                $n['chat_id'] = $conversation->chat_id; // the chat we asked for is authoritative
                [, $created] = $this->recorder->record($n, 'sync');
                $stats['messages_seen']++;
                $created && $stats['messages_new']++;
            }

            $last = $rows ? end($rows) : null;
            $after = is_array($last) && isset($last['id']) ? (string) $last['id'] : null;
            if (count($rows) < $size || $after === null) {
                return;
            }
        }
    }

    private function syncHistory(Conversation $conversation, array &$stats, bool &$unsupported): void
    {
        try {
            $items = $this->client->history($conversation->chat_id, (int) config('openwa.sync.history_limit'));
        } catch (OpenWaException $e) {
            if ($e->status === 501) {
                $unsupported = true;

                return;
            }
            throw $e;
        }

        foreach ($items as $item) {
            $n = is_array($item) ? PayloadNormalizer::fromHistory($item, $conversation->chat_id) : null;
            if ($n === null) {
                continue;
            }
            [, $created] = $this->recorder->record($n, 'history');
            $stats['messages_seen']++;
            $created && $stats['messages_new']++;
        }
    }
}
