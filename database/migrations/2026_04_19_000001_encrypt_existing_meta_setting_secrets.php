<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Run this migration before or with deploying MetaSetting encrypted casts.
 * Existing plaintext values in the database are wrapped with Laravel's encrypt().
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
                if ($this->isAlreadyEncrypted($val)) {
                    continue;
                }
                $updates[$col] = encrypt($val);
            }

            if ($updates !== []) {
                DB::table('meta_settings')->where('id', $row->id)->update($updates);
            }
        }
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            decrypt($value);

            return true;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return false;
        }
    }

    public function down(): void
    {
        // Cannot safely decrypt in down() without risking data loss; no-op.
    }
};
