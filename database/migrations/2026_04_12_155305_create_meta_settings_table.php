<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_settings', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number_id');
            $table->string('waba_id');
            $table->string('app_id');
            $table->string('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('verify_token')->nullable();
            $table->boolean('webhook_verified')->default(false);
            $table->json('webhook_subscriptions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_settings');
    }
};
