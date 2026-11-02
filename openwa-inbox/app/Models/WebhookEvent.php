<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class WebhookEvent extends Model
{
    use Prunable;

    protected $fillable = ['idempotency_key', 'delivery_id', 'event', 'outcome'];

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(14));
    }
}
