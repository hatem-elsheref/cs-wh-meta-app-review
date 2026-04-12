<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@test.com',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'status' => 'approved',
            ]
        );

        User::updateOrCreate(
            ['email' => 'agent@test.com'],
            [
                'name' => 'Agent User',
                'email' => 'agent@test.com',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'status' => 'approved',
            ]
        );

        User::updateOrCreate(
            ['email' => 'pending@test.com'],
            [
                'name' => 'Pending User',
                'email' => 'pending@test.com',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'status' => 'pending',
            ]
        );
    }
}