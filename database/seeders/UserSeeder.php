<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Instructors
        User::factory()->count(20)->create([
            'role' => 'instructor'
        ]);

        // Students
        User::factory()->count(500)->create([
            'role' => 'student'
        ]);
    }
}
