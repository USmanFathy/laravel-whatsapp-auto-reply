<?php

use Illuminate\Support\Facades\Schedule;

// Optional (run `php artisan schedule:work`): re-queues replies stuck in "pending" and prunes old log rows.
Schedule::command('openwa:retry-replies')->everyMinute();
Schedule::command('model:prune')->daily();
