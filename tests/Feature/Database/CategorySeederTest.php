<?php

namespace Tests\Feature\Database;

use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_seeder_is_idempotent(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertDatabaseCount('categories', 5);
        $this->assertDatabaseHas('categories', [
            'slug' => 'makanan-minuman',
            'name' => 'Makanan & Minuman',
            'is_active' => true,
        ]);
    }
}
