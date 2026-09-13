<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('is_group')->default(false);
            $t->string('title', 200)->nullable();
            $t->string('created');
            $t->string('updated');
        });

        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $t->foreignUuid('agent_id')->constrained('agents');
            $t->string('last_read_at')->nullable();
            $t->unique(['conversation_id', 'agent_id']);
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $t->foreignUuid('sender_agent_id')->constrained('agents');
            $t->text('body')->nullable();
            $t->string('created');
            $t->index(['conversation_id', 'created']);
        });

        Schema::create('message_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $t->string('filename', 255);
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
