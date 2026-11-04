<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleTestUserSeeder extends Seeder
{
    /**
     * Seed role-based test users.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Pemilik UMKM Test',
                'email' => 'umkm@test.com',
                'role' => 'pemilik_umkm',
            ],
            [
                'name' => 'Admin BUMDes Test',
                'email' => 'admin@test.com',
                'role' => 'admin_bumdes',
            ],
            [
                'name' => 'Kurir Test',
                'email' => 'kurir@test.com',
                'role' => 'kurir',
            ],
            [
                'name' => 'Kepala BUMDes Test',
                'email' => 'kepala@test.com',
                'role' => 'kepala_bumdes',
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'role' => $userData['role'],
                    'password' => Hash::make('password123'),
                ],
            );
        }
    }
}
