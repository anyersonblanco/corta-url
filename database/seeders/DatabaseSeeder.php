<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed inicial CortarLink — crea el admin de Webtilia.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'ablanco@webtilia.com'],
            [
                'name' => 'Anyerson Blanco',
                'password' => Hash::make('webtilia2026'),
                'email_verified_at' => now(),
            ]
        );
    }
}
