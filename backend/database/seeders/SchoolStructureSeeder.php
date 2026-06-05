<?php

namespace Database\Seeders;

use App\Services\SchoolClassGenerationService;
use Illuminate\Database\Seeder;

class SchoolStructureSeeder extends Seeder
{
    public function run(): void
    {
        app(SchoolClassGenerationService::class)->ensureFixedStructure();
    }
}
