<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->foreignId('flow_id')->nullable()->constrained('flows')->nullOnDelete();
            $table->string('current_node_id')->nullable();
            $table->json('variables')->nullable();
            $table->json('message_history')->nullable();
            $table->enum('mode', ['auto', 'manual'])->default('auto');
            $table->string('language')->default('auto');
            $table->timestamp('mode_revert_at')->nullable();
            $table->json('awaiting_input')->nullable();
            $table->json('rating_pending')->nullable();
            $table->timestamp('session_started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};

