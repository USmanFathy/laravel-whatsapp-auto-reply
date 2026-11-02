<?php

namespace App\Support;

/**
 * Verifies the X-OpenWA-Signature header: "sha256=" + hex(HMAC-SHA256(secret, rawBody)).
 */
final class WebhookSignature
{
    public static function compute(string $rawBody, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verify(string $rawBody, ?string $header, ?string $secret): bool
    {
        if ($secret === null || $secret === '' || $header === null || $header === '') {
            return false;
        }

        return hash_equals(self::compute($rawBody, $secret), trim($header));
    }
}
