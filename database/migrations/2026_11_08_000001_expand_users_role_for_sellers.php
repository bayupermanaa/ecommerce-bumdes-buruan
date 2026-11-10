<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('role', ['pelanggan', 'pemilik_umkm', 'admin_bumdes', 'kurir', 'kepala_bumdes', 'penjual'])
                    ->default('pelanggan')->change();
            });
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Driver database tidak didukung untuk perluasan role penjual.');
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('pelanggan','pemilik_umkm','admin_bumdes','kurir','kepala_bumdes','penjual') NOT NULL DEFAULT 'pelanggan'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('users')->where('role', 'penjual')->exists()) {
                throw new RuntimeException('Rollback ditolak: masih ada akun dengan role penjual.');
            }
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('role', ['pelanggan', 'pemilik_umkm', 'admin_bumdes', 'kurir', 'kepala_bumdes'])
                    ->default('pelanggan')->change();
            });
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Driver database tidak didukung untuk rollback role penjual.');
        }

        if (DB::table('users')->where('role', 'penjual')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada akun dengan role penjual.');
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('pelanggan','pemilik_umkm','admin_bumdes','kurir','kepala_bumdes') NOT NULL DEFAULT 'pelanggan'");
    }
};
