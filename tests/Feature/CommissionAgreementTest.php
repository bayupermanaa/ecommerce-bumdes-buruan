<?php

namespace Tests\Feature;

use App\Models\CommissionAgreement;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\CommissionCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_activate_end_and_keep_commission_agreement_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Komisi');
        $payload = $this->agreementPayload($store, ['commission_percentage' => '12.50']);

        $this->actingAs($admin)
            ->post(route('admin.commission-agreements.store'), $payload)
            ->assertRedirect();

        $agreement = CommissionAgreement::sole();
        $this->assertSame('draft', $agreement->status);
        $this->assertSame($admin->id, $agreement->created_by);
        $this->assertSame('12.50', $agreement->commission_percentage);

        $this->actingAs($admin)
            ->patch(route('admin.commission-agreements.activate', $agreement))
            ->assertRedirect(route('admin.commission-agreements.show', $agreement));

        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertNotNull($agreement->fresh()->activated_at);

        $this->actingAs($admin)
            ->patch(route('admin.commission-agreements.end', $agreement), [
                'effective_end_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('admin.commission-agreements.show', $agreement));

        $agreement->refresh();
        $this->assertSame('ended', $agreement->status);
        $this->assertNotNull($agreement->ended_at);
        $this->actingAs($admin)
            ->get(route('admin.commission-agreements.show', $agreement))
            ->assertOk()
            ->assertSee(['12.50%', 'Berakhir']);
    }

    public function test_percentage_must_be_between_zero_and_one_hundred_with_at_most_two_decimals(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Validasi');

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.create', $store))
            ->post(route('admin.commission-agreements.store'), $this->agreementPayload($store, ['commission_percentage' => '100.01']))
            ->assertRedirect(route('admin.commission-agreements.create', $store))
            ->assertSessionHasErrors('commission_percentage');

        $this->actingAs($admin)
            ->post(route('admin.commission-agreements.store'), $this->agreementPayload($store, ['commission_percentage' => '0.00']))
            ->assertRedirect();

        $this->assertDatabaseHas('commission_agreements', [
            'umkm_store_id' => $store->id,
            'commission_percentage' => 0,
            'status' => 'draft',
        ]);
    }

    public function test_resubmitting_the_same_idempotency_key_returns_the_existing_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Idempotent');
        $payload = $this->agreementPayload($store);

        $this->actingAs($admin)
            ->post(route('admin.commission-agreements.store'), $payload)
            ->assertRedirect();
        $firstAgreement = CommissionAgreement::sole();

        $this->actingAs($admin)
            ->post(route('admin.commission-agreements.store'), $payload)
            ->assertRedirect(route('admin.commission-agreements.show', $firstAgreement))
            ->assertSessionHas('status', 'Draft perjanjian komisi sudah dibuat sebelumnya.');

        $this->assertSame(1, CommissionAgreement::query()->count());
        $this->assertSame($firstAgreement->id, CommissionAgreement::sole()->id);
    }

    public function test_a_new_idempotency_key_allows_a_new_identical_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Draft Baru');
        $firstPayload = $this->agreementPayload($store);
        $secondPayload = [...$firstPayload, 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($admin)->post(route('admin.commission-agreements.store'), $firstPayload);
        $this->actingAs($admin)->post(route('admin.commission-agreements.store'), $secondPayload);

        $this->assertSame(2, CommissionAgreement::query()->count());
        $this->assertCount(2, CommissionAgreement::query()->where('umkm_store_id', $store->id)->get());
    }

    public function test_validation_failure_does_not_consume_the_idempotency_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Validasi Key');
        $payload = $this->agreementPayload($store, ['commission_percentage' => '100.001']);

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.create', $store))
            ->post(route('admin.commission-agreements.store'), $payload)
            ->assertRedirect(route('admin.commission-agreements.create', $store))
            ->assertSessionHasErrors('commission_percentage');
        $this->assertSame(0, CommissionAgreement::query()->count());

        $payload['commission_percentage'] = '10.00';
        $this->actingAs($admin)
            ->post(route('admin.commission-agreements.store'), $payload)
            ->assertRedirect();
        $this->assertSame(1, CommissionAgreement::query()->count());
    }

    public function test_overlapping_active_agreements_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Bertabrakan');
        $existing = $this->agreement($store, $admin, [
            'status' => 'active',
            'effective_start_date' => today()->subDay(),
            'effective_end_date' => null,
            'activated_at' => now(),
        ]);
        $draft = $this->agreement($store, $admin, [
            'status' => 'draft',
            'effective_start_date' => today(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.show', $draft))
            ->patch(route('admin.commission-agreements.activate', $draft))
            ->assertRedirect(route('admin.commission-agreements.show', $draft))
            ->assertSessionHasErrors('agreement');

        $this->assertSame('active', $existing->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_admin_can_cancel_a_draft_without_deleting_its_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Draft Dibatalkan');
        $draft = $this->agreement($store, $admin, ['commission_percentage' => '10.00']);

        $this->actingAs($admin)
            ->patch(route('admin.commission-agreements.cancel', $draft))
            ->assertRedirect(route('admin.commission-agreements.show', $draft))
            ->assertSessionHas('status', 'Draft perjanjian komisi berhasil dibatalkan.');

        $draft->refresh();
        $this->assertSame('cancelled', $draft->status);
        $this->assertDatabaseHas('commission_agreements', ['id' => $draft->id, 'status' => 'cancelled']);
    }

    public function test_non_admin_cannot_cancel_a_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Draft Privat');
        $draft = $this->agreement($store, $admin);

        $this->actingAs($store->user)
            ->patch(route('admin.commission-agreements.cancel', $draft))
            ->assertForbidden();

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_cancelled_draft_cannot_be_cancelled_again_or_activated(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Draft Berulang');
        $draft = $this->agreement($store, $admin, ['status' => 'cancelled']);

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.show', $draft))
            ->patch(route('admin.commission-agreements.cancel', $draft))
            ->assertRedirect(route('admin.commission-agreements.show', $draft))
            ->assertSessionHasErrors('agreement');

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.show', $draft))
            ->patch(route('admin.commission-agreements.activate', $draft))
            ->assertRedirect(route('admin.commission-agreements.show', $draft))
            ->assertSessionHasErrors('agreement');

        $this->assertSame('cancelled', $draft->fresh()->status);
    }

    public function test_active_agreement_cannot_be_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Aktif');
        $active = $this->agreement($store, $admin, [
            'status' => 'active',
            'effective_start_date' => today(),
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.commission-agreements.show', $active))
            ->patch(route('admin.commission-agreements.cancel', $active))
            ->assertRedirect(route('admin.commission-agreements.show', $active))
            ->assertSessionHasErrors('agreement');

        $this->assertSame('active', $active->fresh()->status);
    }

    public function test_non_admin_cannot_manage_agreements_and_owner_only_sees_own_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $firstStore = $this->approvedStore('Toko Pertama');
        $secondStore = $this->approvedStore('Toko Kedua');
        $firstAgreement = $this->agreement($firstStore, $admin, ['commission_percentage' => '10.00']);
        $this->agreement($secondStore, $admin, ['commission_percentage' => '25.00', 'notes' => 'Rahasia toko kedua']);

        $this->actingAs($firstStore->user)
            ->get(route('admin.commission-agreements.index'))
            ->assertForbidden();
        $this->actingAs($firstStore->user)
            ->patch(route('admin.commission-agreements.activate', $firstAgreement))
            ->assertForbidden();
        $this->actingAs($firstStore->user)
            ->get(route('umkm.commission-agreements.index'))
            ->assertOk()
            ->assertSee('10.00%')
            ->assertDontSee(['25.00%', 'Rahasia toko kedua']);
    }

    public function test_service_calculates_from_store_subtotal_and_never_assumes_a_missing_agreement(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->approvedStore('Toko Perhitungan');
        $service = app(CommissionCalculationService::class);

        $this->assertNull($service->calculateForStore($store, '1000.05'));

        $agreement = $this->agreement($store, $admin, [
            'commission_percentage' => '12.50',
            'status' => 'active',
            'effective_start_date' => today(),
            'activated_at' => now(),
        ]);
        $calculation = $service->calculateForStore($store, '1000.05');

        $this->assertSame($agreement->id, $calculation['agreement']->id);
        $this->assertSame('1000.05', $calculation['subtotal']);
        $this->assertSame('125.01', $calculation['commission_amount']);
        $this->assertSame('875.04', $calculation['umkm_amount']);
    }

    private function approvedStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => $name,
            'slug' => fake()->unique()->slug(),
        ]);
        $store->forceFill(['status' => 'approved'])->save();

        return $store->fresh('user');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agreementPayload(UmkmStore $store, array $overrides = []): array
    {
        return array_merge([
            'umkm_store_id' => $store->id,
            'idempotency_key' => (string) Str::uuid(),
            'commission_percentage' => '10.00',
            'effective_start_date' => today()->toDateString(),
            'effective_end_date' => null,
            'notes' => 'Catatan perjanjian.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function agreement(UmkmStore $store, User $admin, array $overrides = []): CommissionAgreement
    {
        return CommissionAgreement::create(array_merge([
            'umkm_store_id' => $store->id,
            'commission_percentage' => '10.00',
            'effective_start_date' => today()->toDateString(),
            'effective_end_date' => null,
            'status' => 'draft',
            'notes' => null,
            'created_by' => $admin->id,
            'activated_at' => null,
            'ended_at' => null,
        ], $overrides));
    }
}
