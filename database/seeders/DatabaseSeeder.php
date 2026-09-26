<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. The administrator account is created directly
     * (see the "user:set-password" command), not seeded here.
     */
    public function run(): void
    {
        $this->call(TaxonomySeeder::class);
    }
}
