<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('wa_message_id')->unique();
            $table->string('direction', 10); // incoming | outgoing
            $table->string('sender')->nullable();
            $table->string('recipient')->nullable();
            $table->string('author')->nullable();
            $table->text('body')->nullable();
            $table->string('type', 32)->default('text');
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->boolean('edited')->default(false);
            $table->string('source', 16); // webhook | sync | history
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
