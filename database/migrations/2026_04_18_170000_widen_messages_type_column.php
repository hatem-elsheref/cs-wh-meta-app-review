<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL ENUM on messages.type rejects Meta types such as "location". Use VARCHAR for all webhook types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `messages` MODIFY COLUMN `type` VARCHAR(32) NOT NULL DEFAULT 'text'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages ALTER COLUMN type TYPE VARCHAR(32)');
        } else {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('type', 32)->default('text')->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting to ENUM risks invalid rows; leave as VARCHAR.
    }
};
