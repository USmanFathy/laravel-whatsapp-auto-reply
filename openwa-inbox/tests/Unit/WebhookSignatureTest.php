<?php

namespace Tests\Unit;

use App\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_matches_the_documented_openwa_format(): void
    {
        $body = '{"event":"message.received"}';
        $expected = 'sha256='.hash_hmac('sha256', $body, 'secret-secret-secret');

        $this->assertSame($expected, WebhookSignature::compute($body, 'secret-secret-secret'));
        $this->assertTrue(WebhookSignature::verify($body, $expected, 'secret-secret-secret'));
    }

    public function test_rejects_wrong_missing_and_empty_inputs(): void
    {
        $sig = WebhookSignature::compute('body', 'right-secret-value');

        $this->assertFalse(WebhookSignature::verify('body', $sig, 'wrong-secret-value'));
        $this->assertFalse(WebhookSignature::verify('body2', $sig, 'right-secret-value'));
        $this->assertFalse(WebhookSignature::verify('body', null, 'right-secret-value'));
        $this->assertFalse(WebhookSignature::verify('body', '', 'right-secret-value'));
        $this->assertFalse(WebhookSignature::verify('body', $sig, ''));
        $this->assertFalse(WebhookSignature::verify('body', $sig, null));
        $this->assertFalse(WebhookSignature::verify('body', substr($sig, 7), 'right-secret-value'));
    }
}
