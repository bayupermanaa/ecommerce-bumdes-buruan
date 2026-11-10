<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\RoleTestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleTestUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_test_user_seeder_creates_expected_users_and_is_idempotent(): void
    {
        $this->seed(RoleTestUserSeeder::class);
        $this->seed(RoleTestUserSeeder::class);

        $this->assertDatabaseCount('users', 4);

        $expectedUsers = [
            ['email' => 'umkm@test.com', 'name' => 'Penjual Test', 'role' => 'penjual'],
            ['email' => 'admin@test.com', 'name' => 'Admin BUMDes Test', 'role' => 'admin_bumdes'],
            ['email' => 'kurir@test.com', 'name' => 'Kurir Test', 'role' => 'kurir'],
            ['email' => 'kepala@test.com', 'name' => 'Kepala BUMDes Test', 'role' => 'kepala_bumdes'],
        ];

        foreach ($expectedUsers as $expectedUser) {
            $user = User::where('email', $expectedUser['email'])->first();

            $this->assertNotNull($user);
            $this->assertSame($expectedUser['name'], $user->name);
            $this->assertSame($expectedUser['role'], $user->role);
            $this->assertTrue(Hash::check('password123', $user->password));
        }
    }
}
