<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BootstrapAdminBumdesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_only_the_first_admin_with_a_hashed_password_and_dashboard_access(): void
    {
        $password = $this->strongPassword();

        $this->artisan('app:bootstrap-admin')
            ->expectsQuestion('Nama Admin BUMDes', 'Admin Sintetis')
            ->expectsQuestion('Email Admin BUMDes', '  ADMIN@EXAMPLE.TEST  ')
            ->expectsQuestion('Password Admin BUMDes', $password)
            ->expectsQuestion('Ulangi password Admin BUMDes', $password)
            ->expectsQuestion('Ketik BUAT ADMIN BUMDES untuk melanjutkan', 'BUAT ADMIN BUMDES')
            ->assertExitCode(0);

        $admin = User::query()->sole();
        $this->assertSame('admin_bumdes', $admin->role);
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertNotSame($password, $admin->password);
        $this->assertTrue(Hash::check($password, $admin->password));

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => $password,
        ])->assertRedirect(route('admin.dashboard', absolute: false));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('umkm.dashboard'))->assertForbidden();
    }

    public function test_existing_admin_blocks_duplicate_creation_without_prompts(): void
    {
        $original = User::factory()->create(['role' => 'admin_bumdes']);
        $originalPassword = $original->password;

        $this->artisan('app:bootstrap-admin')->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalPassword, $original->fresh()->password);
    }

    public function test_existing_email_is_never_promoted_or_modified(): void
    {
        $customer = User::factory()->create(['email' => 'existing@example.test', 'role' => 'pelanggan']);
        $originalPassword = $customer->password;
        $password = $this->strongPassword();

        $this->artisan('app:bootstrap-admin')
            ->expectsQuestion('Nama Admin BUMDes', 'Admin Sintetis')
            ->expectsQuestion('Email Admin BUMDes', 'existing@example.test')
            ->expectsQuestion('Password Admin BUMDes', $password)
            ->expectsQuestion('Ulangi password Admin BUMDes', $password)
            ->expectsQuestion('Ketik BUAT ADMIN BUMDES untuk melanjutkan', 'BUAT ADMIN BUMDES')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('pelanggan', $customer->fresh()->role);
        $this->assertSame($originalPassword, $customer->fresh()->password);
    }

    public function test_weak_password_is_rejected_without_creating_an_admin(): void
    {
        $this->artisan('app:bootstrap-admin')
            ->expectsQuestion('Nama Admin BUMDes', 'Admin Sintetis')
            ->expectsQuestion('Email Admin BUMDes', 'admin@example.test')
            ->expectsQuestion('Password Admin BUMDes', 'weak')
            ->expectsQuestion('Ulangi password Admin BUMDes', 'weak')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mismatched_password_is_rejected_without_creating_an_admin(): void
    {
        $strong = $this->strongPassword();

        $this->artisan('app:bootstrap-admin')
            ->expectsQuestion('Nama Admin BUMDes', 'Admin Sintetis')
            ->expectsQuestion('Email Admin BUMDes', 'admin@example.test')
            ->expectsQuestion('Password Admin BUMDes', $strong)
            ->expectsQuestion('Ulangi password Admin BUMDes', $strong.'x')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_confirmation_is_rejected_without_creating_an_admin(): void
    {
        $strong = $this->strongPassword();

        $this->artisan('app:bootstrap-admin')
            ->expectsQuestion('Nama Admin BUMDes', 'Admin Sintetis')
            ->expectsQuestion('Email Admin BUMDes', 'admin@example.test')
            ->expectsQuestion('Password Admin BUMDes', $strong)
            ->expectsQuestion('Ulangi password Admin BUMDes', $strong)
            ->expectsQuestion('Ketik BUAT ADMIN BUMDES untuk melanjutkan', 'BATAL')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_and_production_runs_are_rejected(): void
    {
        $this->artisan('app:bootstrap-admin', ['--no-interaction' => true])->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);

        app()->detectEnvironment(fn (): string => 'production');
        $this->artisan('app:bootstrap-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    private function strongPassword(): string
    {
        return Str::random(20).'aA1!';
    }
}
