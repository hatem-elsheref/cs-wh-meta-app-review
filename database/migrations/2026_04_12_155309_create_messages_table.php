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
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->string('meta_message_id')->nullable()->unique();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('type', ['text', 'template', 'image', 'audio', 'video', 'document', 'sticker']);
            $table->text('content')->nullable();
            $table->text('template_name')->nullable();
            $table->json('template_components')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
