<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The inbox (conversations/messages) is gone; auto_reply_logs is rebuilt around WhatsApp message ids.
        Schema::dropIfExists('auto_reply_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');

        Schema::create('auto_reply_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('auto_reply_logs', function (Blueprint $table) {
            $table->id();
            // One reply per WhatsApp message, enforced by the database.
            $table->string('wa_message_id')->unique();
            $table->string('chat_id')->index();
            $table->string('status', 16); // pending | sent | failed | skipped
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('openwa_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_logs');
        Schema::dropIfExists('auto_reply_settings');
    }
};
