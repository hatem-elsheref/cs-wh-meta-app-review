<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('openai');
            $table->string('model')->default('gpt-4o-mini');
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->string('default_language')->default('auto');
            $table->string('default_tone')->default('helpful');
            $table->text('system_prompt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};

