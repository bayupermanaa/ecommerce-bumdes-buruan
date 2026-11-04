<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the initial product categories.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Makanan & Minuman', 'slug' => 'makanan-minuman'],
            ['name' => 'Kerajinan', 'slug' => 'kerajinan'],
            ['name' => 'Fashion', 'slug' => 'fashion'],
            ['name' => 'Pertanian', 'slug' => 'pertanian'],
            ['name' => 'Jasa', 'slug' => 'jasa'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
