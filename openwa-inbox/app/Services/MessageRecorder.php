<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MessageRecorder
{
    /**
     * Persists a normalized message exactly once (unique wa_message_id).
     *
     * @return array{0:Message,1:bool,2:Conversation} message, created?, conversation
     */
    public function record(array $n, string $source, bool $countUnread = false): array
    {
        try {
            return DB::transaction(function () use ($n, $source, $countUnread) {
                $conversation = $this->conversationFor($n);

                $existing = Message::where('wa_message_id', $n['wa_id'])->first();
                if ($existing) {
                    return [$existing, false, $conversation];
                }

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'wa_message_id' => $n['wa_id'],
                    'direction' => $n['direction'],
                    'sender' => $n['sender'],
                    'recipient' => $n['recipient'],
                    'author' => $n['author'],
                    'body' => $n['body'],
                    'type' => $n['type'],
                    'sent_at' => $n['sent_at'],
                    'status' => $n['status'],
                    'source' => $source,
                ]);

                $changes = [];
                if ($message->sent_at === null || $conversation->last_message_at === null || $message->sent_at->gte($conversation->last_message_at)) {
                    $changes['last_message_at'] = $message->sent_at ?? now();
                    $changes['last_message_preview'] = self::preview($message);
                }
                if ($countUnread && $message->isIncoming()) {
                    $changes['unread_count'] = $conversation->unread_count + 1;
                }
                if ($changes) {
                    $conversation->update($changes);
                }

                return [$message, true, $conversation->refresh()];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request stored the same WhatsApp message first.
            $message = Message::where('wa_message_id', $n['wa_id'])->firstOrFail();

            return [$message, false, $message->conversation];
        }
    }

    public function conversationFor(array $n): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['chat_id' => $n['chat_id']],
            ['is_group' => $n['is_group'], 'kind' => $n['kind'] ?? null, 'name' => $n['chat_name'] ?? null],
        );

        if (($n['chat_name'] ?? null) && $conversation->name !== $n['chat_name']) {
            $conversation->update(['name' => $n['chat_name']]);
        }

        return $conversation;
    }

    public static function preview(Message $message): string
    {
        $text = trim((string) $message->body);
        if ($text === '') {
            $text = '['.$message->type.']';
        }

        return mb_substr($text, 0, 250);
    }
}
