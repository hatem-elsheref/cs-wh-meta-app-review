<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('order_number')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['phone', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};

