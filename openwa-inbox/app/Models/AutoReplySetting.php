<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single, global automatic reply (one row, id = 1). */
class AutoReplySetting extends Model
{
    protected $fillable = ['enabled', 'message'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['enabled' => false, 'message' => null]);
    }

    public function replyText(): string
    {
        return trim((string) $this->message);
    }
}
