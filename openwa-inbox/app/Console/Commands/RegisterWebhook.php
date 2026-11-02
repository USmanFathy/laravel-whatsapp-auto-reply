<?php

namespace App\Console\Commands;

use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;
use Illuminate\Console\Command;

class RegisterWebhook extends Command
{
    protected $signature = 'openwa:register-webhook';

    protected $description = 'Create (or update) the OpenWA webhook that delivers signed events to this app';

    public function handle(OpenWaClient $client): int
    {
        $secret = (string) config('openwa.webhook_secret');
        if (strlen($secret) < 16) {
            $this->error('OPENWA_WEBHOOK_SECRET must be set and at least 16 characters long.');

            return self::FAILURE;
        }

        $body = [
            'url' => config('openwa.webhook_url'),
            'events' => config('openwa.webhook_events'),
            'secret' => $secret,
        ];

        try {
            $existing = collect($client->webhooks())->first(fn ($w) => is_array($w) && ($w['url'] ?? null) === $body['url']);
            if ($existing && isset($existing['id'])) {
                $client->updateWebhook((string) $existing['id'], $body + ['active' => true]);
                $this->info("Webhook {$existing['id']} updated.");
            } else {
                $created = $client->createWebhook($body);
                $this->info('Webhook created'.(isset($created['id']) ? ": {$created['id']}" : '').'.');
            }
        } catch (OpenWaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
