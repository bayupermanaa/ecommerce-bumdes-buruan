<?php

namespace App\Services;

use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmRecipientBankAccount;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\UmkmDisbursementStatus;
use App\Support\UmkmEntitlementEventType;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UmkmDisbursementRequestService
{
    /**
     * Reserve every currently available entitlement for one store.
     * Monetary arithmetic is performed in integer minor units, never floats.
     */
    public function request(UmkmStore $store, User $owner, string $idempotencyKey): UmkmDisbursement
    {
        $this->ensureOwner($store, $owner);

        return DB::transaction(function () use ($store, $owner, $idempotencyKey): UmkmDisbursement {
            // This is the shared serialization point for reconciliation and requests.
            $lockedStore = UmkmStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

            $existing = UmkmDisbursement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->umkm_store_id !== $lockedStore->id || $existing->requested_by !== $owner->id) {
                    throw new AuthorizationException('Kunci idempotensi tidak valid untuk toko ini.');
                }

                return $existing;
            }

            $account = UmkmRecipientBankAccount::query()
                ->where('umkm_store_id', $lockedStore->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $account || ! $account->verified_at) {
                throw ValidationException::withMessages([
                    'recipient_bank_account' => 'Rekening penerima aktif yang telah diverifikasi wajib tersedia.',
                ]);
            }

            $entitlements = UmkmEntitlement::query()
                ->where('umkm_store_id', $lockedStore->id)
                ->where('status', UmkmEntitlementStatus::AVAILABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($entitlements->isEmpty()) {
                throw ValidationException::withMessages(['entitlements' => 'Belum ada saldo tersedia untuk diajukan.']);
            }

            $active = UmkmDisbursement::query()
                ->where('active_request_lock', 'store:'.$lockedStore->id)
                ->lockForUpdate()
                ->first();
            if ($active) {
                throw ValidationException::withMessages(['disbursement' => 'Masih ada pengajuan pencairan aktif untuk toko ini.']);
            }

            $minorUnits = 0;
            foreach ($entitlements as $entitlement) {
                $minorUnits += $this->toMinorUnits($entitlement->source_umkm_amount);
            }
            if ($minorUnits <= 0) {
                throw ValidationException::withMessages(['entitlements' => 'Total saldo tersedia harus lebih besar dari nol.']);
            }

            $disbursement = UmkmDisbursement::create([
                'umkm_store_id' => $lockedStore->id,
                'recipient_bank_account_id' => $account->id,
                'recipient_bank_name' => $account->bank_name,
                'recipient_account_holder_name' => $account->account_holder_name,
                'recipient_account_number' => $account->account_number,
                'requested_amount' => $this->fromMinorUnits($minorUnits),
                'status' => UmkmDisbursementStatus::REQUESTED,
                'active_request_lock' => 'store:'.$lockedStore->id,
                'requested_by' => $owner->id,
                'requested_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($entitlements as $entitlement) {
                $amount = $this->fromMinorUnits($this->toMinorUnits($entitlement->source_umkm_amount));
                UmkmDisbursementAllocation::create([
                    'umkm_disbursement_id' => $disbursement->id,
                    'umkm_entitlement_id' => $entitlement->id,
                    'amount' => $amount,
                    'entitlement_reservation_lock' => 'entitlement:'.$entitlement->id,
                ]);

                $entitlement->forceFill(['status' => UmkmEntitlementStatus::RESERVED])->save();
                UmkmEntitlementEvent::create([
                    'umkm_entitlement_id' => $entitlement->id,
                    'actor_id' => $owner->id,
                    'event_type' => UmkmEntitlementEventType::RESERVED,
                    'amount' => $amount,
                    'from_status' => UmkmEntitlementStatus::AVAILABLE,
                    'to_status' => UmkmEntitlementStatus::RESERVED,
                    'metadata' => ['umkm_disbursement_id' => $disbursement->id],
                    'idempotency_key' => $this->eventKey('entitlement', $entitlement->id, $idempotencyKey),
                ]);
            }

            UmkmDisbursementEvent::create([
                'umkm_disbursement_id' => $disbursement->id,
                'actor_id' => $owner->id,
                'event_type' => UmkmDisbursementStatus::REQUESTED,
                'to_status' => UmkmDisbursementStatus::REQUESTED,
                'metadata' => ['entitlement_count' => $entitlements->count()],
                'idempotency_key' => $this->eventKey('disbursement', $disbursement->id, $idempotencyKey),
            ]);

            return $disbursement;
        });
    }

    private function ensureOwner(UmkmStore $store, User $owner): void
    {
        if ($owner->role !== 'pemilik_umkm' || $store->user_id !== $owner->id) {
            throw new AuthorizationException('Hanya pemilik toko yang dapat mengajukan pencairan.');
        }
    }

    private function toMinorUnits(?string $amount): int
    {
        if (! is_string($amount) || ! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['entitlements' => 'Nominal hak UMKM tidak valid.']);
        }

        return ((int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0')) * ($matches[1] === '-' ? -1 : 1);
    }

    private function fromMinorUnits(int $amount): string
    {
        return sprintf('%s%d.%02d', $amount < 0 ? '-' : '', intdiv(abs($amount), 100), abs($amount) % 100);
    }

    private function eventKey(string $scope, int $id, string $requestKey): string
    {
        $hash = md5('umkm-disbursement:'.$scope.':'.$id.':'.$requestKey);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
