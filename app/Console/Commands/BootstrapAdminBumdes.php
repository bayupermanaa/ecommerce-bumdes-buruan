<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class BootstrapAdminBumdes extends Command
{
    protected $signature = 'app:bootstrap-admin';

    protected $description = 'Create the first Admin BUMDes interactively on the approved local database.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! $this->input->isInteractive()) {
            $this->error('Command hanya tersedia secara interaktif pada environment local atau testing.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection();

            if (! $this->safeTarget($connection)) {
                $this->error('Target database tidak diizinkan untuk bootstrap Admin.');

                return self::FAILURE;
            }

            if (User::query()->where('role', 'admin_bumdes')->exists()) {
                $this->warn('Akun Admin BUMDes sudah ada. Tidak ada akun dibuat.');

                return self::FAILURE;
            }

            $name = trim((string) $this->ask('Nama Admin BUMDes'));
            $email = mb_strtolower(trim((string) $this->ask('Email Admin BUMDes')));
            $identity = Validator::make(compact('name', 'email'), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
            ]);

            if ($identity->fails()) {
                $this->error('Nama atau email Admin tidak valid.');

                return self::FAILURE;
            }

            $password = $this->secret('Password Admin BUMDes', false);
            $confirmation = $this->secret('Ulangi password Admin BUMDes', false);

            if (! is_string($password) || ! is_string($confirmation)
                || ! hash_equals($password, $confirmation)
                || Validator::make(['password' => $password], [
                    'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
                ])->fails()) {
                $this->error('Password tidak cocok atau tidak memenuhi persyaratan keamanan.');

                return self::FAILURE;
            }

            $this->warn('Akun Admin pertama akan dibuat pada database yang telah diverifikasi.');

            if ($this->ask('Ketik BUAT ADMIN BUMDES untuk melanjutkan') !== 'BUAT ADMIN BUMDES') {
                $this->warn('Konfirmasi tidak cocok. Tidak ada akun dibuat.');

                return self::FAILURE;
            }

            $lockName = 'bumdes_bootstrap_admin_'.$connection->getDatabaseName();
            $locked = false;

            try {
                if ($connection->getDriverName() === 'mysql') {
                    $locked = (int) $connection->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired === 1;

                    if (! $locked) {
                        $this->error('Bootstrap Admin sedang diproses oleh proses lain.');

                        return self::FAILURE;
                    }
                }

                $created = $connection->transaction(function () use ($name, $email, $password): bool {
                    if (User::query()->where('role', 'admin_bumdes')->exists()
                        || User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        return false;
                    }

                    User::query()->create([
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make($password),
                        'role' => 'admin_bumdes',
                    ]);

                    return true;
                });

                if (! $created) {
                    $this->warn('Admin atau email sudah terdaftar. Tidak ada akun lain yang diubah.');

                    return self::FAILURE;
                }
            } finally {
                if ($locked) {
                    $connection->select('SELECT RELEASE_LOCK(?)', [$lockName]);
                }
            }

            $this->info('Akun Admin BUMDes pertama berhasil dibuat.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Database and console errors must never expose the submitted password.
            $this->error('Bootstrap Admin gagal tanpa mengubah akun yang sudah ada. Periksa koneksi dan status database.');

            return self::FAILURE;
        }
    }

    private function safeTarget(ConnectionInterface $connection): bool
    {
        if (app()->environment('testing')) {
            return $connection->getDriverName() === 'sqlite'
                && $connection->getDatabaseName() === ':memory:';
        }

        $config = $connection->getConfig();

        return $connection->getDriverName() === 'mysql'
            && ($config['database'] ?? null) === 'ecommerce_bumdes_restore'
            && in_array($config['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && (string) ($config['port'] ?? '') === '3306'
            && $connection->selectOne('SELECT DATABASE() AS database_name')->database_name === 'ecommerce_bumdes_restore';
    }
}
