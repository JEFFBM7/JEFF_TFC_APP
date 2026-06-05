<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        $this->call(SchoolStructureSeeder::class);

        User::query()->updateOrCreate(
            ['email' => 'admin@educonnect.test'],
            [
                'name' => 'Administrateur',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );

        foreach (AppSetting::KEYS as $key => $meta) {
            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $meta['default'],
                    'description' => $meta['description'] ?? null,
                ],
            );
        }

        AppSetting::flushCache();
    }
}
