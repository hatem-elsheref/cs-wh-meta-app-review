<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->json('nodes_json');
            $table->json('edges_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};

