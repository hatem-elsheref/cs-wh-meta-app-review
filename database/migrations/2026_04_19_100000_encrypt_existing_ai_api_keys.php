<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_settings')) {
            return;
        }

        $rows = DB::table('ai_settings')->select('id', 'api_key')->get();

        foreach ($rows as $row) {
            $val = $row->api_key;
            if (! is_string($val) || $val === '') {
                continue;
            }
            if ($this->isAlreadyEncrypted($val)) {
                continue;
            }
            DB::table('ai_settings')->where('id', $row->id)->update([
                'api_key' => encrypt($val),
            ]);
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
        //
    }
};
