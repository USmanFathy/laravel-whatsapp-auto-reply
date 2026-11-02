<?php

namespace App\Console\Commands;

use App\Services\OpenWa\OpenWaException;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncOpenWa extends Command
{
    protected $signature = 'openwa:sync {--history : Also request live WhatsApp Web history (whatsapp-web.js engine only)} {--chats= : Limit the number of chats}';

    protected $description = 'Synchronize chats and messages from OpenWA into the inbox';

    public function handle(SyncService $sync): int
    {
        try {
            $r = $sync->run((bool) $this->option('history'), $this->option('chats') !== null ? (int) $this->option('chats') : null);
        } catch (OpenWaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Chats: {$r['chats']}  New messages: {$r['messages_new']}  Seen: {$r['messages_seen']}");
        if ($r['history_supported'] === false) {
            $this->warn('Live history is not supported by this OpenWA engine (HTTP 501); stored messages were still synced.');
        }
        foreach ($r['errors'] as $error) {
            $this->warn($error);
        }

        return $r['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
