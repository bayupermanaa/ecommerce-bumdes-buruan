<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $cases = [
            'pelanggan' => route('pelanggan.dashboard', absolute: false),
            'pemilik_umkm' => route('umkm.dashboard', absolute: false),
            'penjual' => route('umkm.dashboard', absolute: false),
            'admin_bumdes' => route('admin.dashboard', absolute: false),
            'kurir' => route('kurir.dashboard', absolute: false),
            'kepala_bumdes' => route('kepala-bumdes.dashboard', absolute: false),
        ];

        foreach ($cases as $role => $expectedRedirect) {
            $user = User::factory()->create([
                'role' => $role,
            ]);

            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $this->assertAuthenticated();
            $response->assertRedirect($expectedRedirect);

            $this->post('/logout');
        }
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create(['role' => 'pelanggan']);

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
        $this->get(route('pelanggan.dashboard'))
            ->assertRedirect(route('login'));
    }
}
