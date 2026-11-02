<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Converts the three real OpenWA message shapes into one internal shape:
 *  - webhook `data` of message.received / message.sent
 *  - rows of GET /api/sessions/{id}/messages (OpenWA's stored messages)
 *  - items of GET /api/sessions/{id}/messages/{chatId}/history (live engine history)
 */
final class PayloadNormalizer
{
    public static function isIgnoredChat(?string $chatId, ?string $kind = null): bool
    {
        return $chatId === null || $chatId === ''
            || $chatId === 'status@broadcast'
            || in_array($kind, ['status', 'broadcast'], true);
    }

    public static function fromWebhook(array $data): ?array
    {
        $waId = self::str($data['id'] ?? null);
        $fromMe = (bool) ($data['fromMe'] ?? false);
        $chatId = self::str($data['chatId'] ?? null) ?? self::str($fromMe ? ($data['to'] ?? null) : ($data['from'] ?? null));
        $kind = self::str($data['kind'] ?? null);

        if ($waId === null || self::isIgnoredChat($chatId, $kind)) {
            return null;
        }

        $contact = is_array($data['contact'] ?? null) ? $data['contact'] : [];
        $isGroup = (bool) ($data['isGroup'] ?? str_ends_with($chatId, '@g.us'));

        return [
            'wa_id' => $waId,
            'chat_id' => $chatId,
            'direction' => $fromMe ? 'outgoing' : 'incoming',
            'sender' => self::str($data['from'] ?? null),
            'recipient' => self::str($data['to'] ?? null),
            'author' => self::str($data['author'] ?? null),
            'body' => self::str($data['body'] ?? null) ?? '',
            'type' => self::str($data['type'] ?? null) ?? 'text',
            'sent_at' => self::time($data['timestamp'] ?? null),
            'chat_name' => (! $fromMe && ! $isGroup) ? (self::str($contact['pushName'] ?? null) ?? self::str($contact['name'] ?? null)) : null,
            'is_group' => $isGroup,
            'kind' => $kind,
            'status' => $fromMe ? 'sent' : null,
        ];
    }

    public static function fromStored(array $row): ?array
    {
        $waId = self::str($row['waMessageId'] ?? null);
        $chatId = self::str($row['chatId'] ?? null);
        if ($waId === null || self::isIgnoredChat($chatId)) {
            return null;
        }

        $outgoing = ($row['direction'] ?? '') === 'outgoing';

        return [
            'wa_id' => $waId,
            'chat_id' => $chatId,
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'sender' => self::str($row['from'] ?? null),
            'recipient' => self::str($row['to'] ?? null),
            'author' => self::str($row['author'] ?? null),
            'body' => self::str($row['body'] ?? null) ?? '',
            'type' => self::str($row['type'] ?? null) ?? 'text',
            'sent_at' => self::time($row['timestamp'] ?? null),
            'chat_name' => self::str($row['chatName'] ?? null),
            'is_group' => str_ends_with($chatId, '@g.us'),
            'kind' => null,
            'status' => self::str($row['status'] ?? null),
        ];
    }

    public static function fromHistory(array $item, string $chatId): ?array
    {
        $waId = self::str($item['id'] ?? null);
        if ($waId === null || self::isIgnoredChat($chatId, self::str($item['kind'] ?? null))) {
            return null;
        }
        $fromMe = (bool) ($item['fromMe'] ?? false);

        return [
            'wa_id' => $waId,
            'chat_id' => $chatId,
            'direction' => $fromMe ? 'outgoing' : 'incoming',
            'sender' => self::str($item['from'] ?? null),
            'recipient' => self::str($item['to'] ?? null),
            'author' => self::str($item['author'] ?? null),
            'body' => self::str($item['body'] ?? null) ?? '',
            'type' => self::str($item['type'] ?? null) ?? 'text',
            'sent_at' => self::time($item['timestamp'] ?? null),
            'chat_name' => null,
            'is_group' => (bool) ($item['isGroup'] ?? str_ends_with($chatId, '@g.us')),
            'kind' => self::str($item['kind'] ?? null),
            'status' => $fromMe ? 'sent' : null,
        ];
    }

    /** Accepts unix seconds (OpenWA), unix milliseconds, or an ISO-8601 string. */
    public static function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (float) $value;

            return Carbon::createFromTimestampUTC((int) ($n > 100000000000 ? $n / 1000 : $n));
        }
        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);
    }
}
