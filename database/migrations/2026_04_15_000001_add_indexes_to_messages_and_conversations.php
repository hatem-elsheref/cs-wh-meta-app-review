<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_at_idx');
            $table->index(['conversation_id', 'id'], 'messages_conversation_id_id_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['last_message_at'], 'conversations_last_message_at_idx');
            $table->index(['window_expires_at'], 'conversations_window_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_created_at_idx');
            $table->dropIndex('messages_conversation_id_id_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_last_message_at_idx');
            $table->dropIndex('conversations_window_expires_at_idx');
        });
    }
};

