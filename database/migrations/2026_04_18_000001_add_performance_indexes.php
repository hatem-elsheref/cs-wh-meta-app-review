<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Leftmost column `created_at` serves time-range scans; trailing columns help filtered dashboard metrics.
            $table->index(['created_at', 'direction', 'type', 'status'], 'messages_metrics_range_idx');
        });

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->index(['created_at', 'event_type'], 'webhook_logs_created_at_event_idx');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index('phone_number', 'contacts_phone_number_idx');
            $table->index('created_at', 'contacts_created_at_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['contact_id', 'last_message_at'], 'conversations_contact_last_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_metrics_range_idx');
        });

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropIndex('webhook_logs_created_at_event_idx');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_phone_number_idx');
            $table->dropIndex('contacts_created_at_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_contact_last_msg_idx');
        });
    }
};
