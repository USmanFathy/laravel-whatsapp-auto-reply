<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // One automatic reply per triggering message, enforced by the database.
            $table->foreignId('trigger_message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->text('reply_text');
            $table->string('status', 16); // pending | sent | failed | skipped
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('openwa_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_logs');
    }
};
