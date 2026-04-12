<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('message_id')->nullable();
            $table->string('status')->nullable();
            $table->json('payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};