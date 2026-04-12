<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('meta_template_id')->unique();
            $table->string('name');
            $table->string('language');
            $table->string('category');
            $table->text('content')->nullable();
            $table->text('header_content')->nullable();
            $table->text('footer_content')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('quality_score')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
