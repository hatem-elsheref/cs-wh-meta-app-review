<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_settings', function (Blueprint $table) {
            $table->text('app_secret')->change();
            $table->text('access_token')->change();
        });
    }

    public function down(): void
    {
        Schema::table('meta_settings', function (Blueprint $table) {
            $table->string('app_secret', 255)->change();
            $table->string('access_token', 255)->change();
        });
    }
};