<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_access_their_own_dashboard(): void
    {
        $cases = [
            'pelanggan' => route('pelanggan.dashboard', absolute: false),
            'pemilik_umkm' => route('umkm.dashboard', absolute: false),
            'admin_bumdes' => route('admin.dashboard', absolute: false),
            'kurir' => route('kurir.dashboard', absolute: false),
            'kepala_bumdes' => route('kepala-bumdes.dashboard', absolute: false),
        ];

        foreach ($cases as $role => $uri) {
            $user = User::factory()->create([
                'role' => $role,
            ]);

            $this->actingAs($user)
                ->get($uri)
                ->assertOk();
        }
    }

    public function test_users_are_blocked_from_other_dashboards(): void
    {
        $cases = [
            ['role' => 'pelanggan', 'uri' => route('admin.dashboard', absolute: false)],
            ['role' => 'pelanggan', 'uri' => route('umkm.dashboard', absolute: false)],
            ['role' => 'pemilik_umkm', 'uri' => route('admin.dashboard', absolute: false)],
            ['role' => 'kurir', 'uri' => route('kepala-bumdes.dashboard', absolute: false)],
        ];

        foreach ($cases as $case) {
            $user = User::factory()->create([
                'role' => $case['role'],
            ]);

            $this->actingAs($user)
                ->get($case['uri'])
                ->assertForbidden();
        }
    }
}
