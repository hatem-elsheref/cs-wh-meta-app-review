<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender_kind', 32)->default('system')->after('direction');
            $table->foreignId('sent_by_user_id')->nullable()->after('sender_kind')->constrained('users')->nullOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('created_via', 32)->nullable()->after('opt_in');
        });

        DB::table('messages')->where('direction', 'inbound')->update([
            'sender_kind' => 'contact',
            'sent_by_user_id' => null,
        ]);

        DB::table('messages')->where('direction', 'outbound')->update([
            'sender_kind' => 'system',
            'sent_by_user_id' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn(['sender_kind', 'sent_by_user_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
