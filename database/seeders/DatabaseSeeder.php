<?php

namespace Database\Seeders;

use App\Models\MetaSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        MetaSetting::query()->create([
            'phone_number_id'   => env('PHONE_NUMBER_ID'),
            'waba_id'           => env('WABA_ID'),
            'app_id'            => env('APP_ID'),
            'app_secret'        => env('APP_SECRET'),
            'access_token'      => env('ACCESS_TOKEN'),
            'webhook_url'       => env('WEBHOOK_URL'),
            'verify_token'      => env('VERIFY_TOKEN'),
        ]);

        $this->call(UserSeeder::class);
    }
}
