<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateStorageSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_local_disk_is_not_auto_served(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_known_private_path_cannot_be_read_through_public_storage_url(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('payment-proofs/known-secret.pdf', 'private');

        $this->get('/storage/payment-proofs/known-secret.pdf')
            ->assertNotFound();
    }

    public function test_public_disk_remains_available_for_product_images(): void
    {
        Storage::fake('public');

        $disk = Storage::disk('public');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertSame('public', config('filesystems.disks.public.visibility'));
        $this->assertStringContainsString('/storage', (string) config('filesystems.disks.public.url'));
    }
}
