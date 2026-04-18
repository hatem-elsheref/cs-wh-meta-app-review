<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store access_token, app_secret, and verify_token as plaintext again (no model encryption).
 * Rows that still hold Laravel-encrypted payloads are decrypted in place using the current APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('meta_settings')) {
            return;
        }

        $rows = DB::table('meta_settings')->select('id', 'access_token', 'app_secret', 'verify_token')->get();

        foreach ($rows as $row) {
            $updates = [];

            foreach (['access_token', 'app_secret', 'verify_token'] as $col) {
                $val = $row->{$col};
                if (! is_string($val) || $val === '') {
                    continue;
                }
                try {
                    $updates[$col] = decrypt($val);
                } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                    // Already plaintext or unreadable; leave unchanged.
                }
            }

            if ($updates !== []) {
                DB::table('meta_settings')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Intentionally no-op: re-encrypting would require restoring encrypted casts.
    }
};
