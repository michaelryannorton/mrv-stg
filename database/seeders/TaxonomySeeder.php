<?php

namespace Database\Seeders;

use App\Models\Audience;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaxonomySeeder extends Seeder
{
    /**
     * Representative starting taxonomy, matching the categories/audiences named throughout
     * community/reference/Community Calendar Technical Specification.md. Real usage will show
     * which of these actually get used and what's missing.
     */
    public function run(): void
    {
        foreach ([
            'Local Music', 'Entrepreneurship', 'Art', 'Family Activities', 'Gaming',
            'Outdoor Recreation', 'Books', 'Local Government', 'Nightlife', 'Food',
            'Writers', 'Crafts',
        ] as $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }

        foreach ([
            'All Ages', 'Adults', 'Families', 'Children', 'Seniors',
        ] as $name) {
            Audience::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
