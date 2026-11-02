<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_group')->default(false);
            $table->string('kind', 32)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_message_preview', 255)->nullable();

            // Per-conversation automatic reply settings.
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_text')->nullable();
            $table->unsignedInteger('auto_reply_cooldown_seconds')->nullable();
            $table->timestamp('last_auto_reply_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
