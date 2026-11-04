<?php

namespace App\Services;

use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\UmkmDisbursementStatus;
use App\Support\UmkmEntitlementEventType;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UmkmDisbursementApprovalService
{
    public function startReview(UmkmDisbursement $disbursement, User $admin): UmkmDisbursement
    {
        $this->ensureRole($admin, 'admin_bumdes');

        return $this->transaction($disbursement, function (UmkmDisbursement $locked) use ($admin): UmkmDisbursement {
            if ($locked->status === UmkmDisbursementStatus::UNDER_REVIEW) return $locked;
            if ($locked->status !== UmkmDisbursementStatus::REQUESTED) $this->invalidStatus('Pengajuan tidak dapat mulai diperiksa.');
            $locked->forceFill(['status' => UmkmDisbursementStatus::UNDER_REVIEW, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $this->event($locked, $admin, 'review_started', UmkmDisbursementStatus::REQUESTED, UmkmDisbursementStatus::UNDER_REVIEW);
            return $locked;
        });
    }

    public function completeReview(UmkmDisbursement $disbursement, User $admin): UmkmDisbursement
    {
        $this->ensureRole($admin, 'admin_bumdes');

        return $this->transaction($disbursement, function (UmkmDisbursement $locked) use ($admin): UmkmDisbursement {
            if ($locked->status !== UmkmDisbursementStatus::UNDER_REVIEW) $this->invalidStatus('Pengajuan tidak sedang diperiksa.');
            if ($this->hasEvent($locked, 'review_passed')) return $locked;
            $this->validateReservations($locked);
            $this->event($locked, $admin, 'review_passed', UmkmDisbursementStatus::UNDER_REVIEW, UmkmDisbursementStatus::UNDER_REVIEW);
            return $locked;
        });
    }

    public function approve(UmkmDisbursement $disbursement, User $head): UmkmDisbursement
    {
        $this->ensureRole($head, 'kepala_bumdes');

        return $this->transaction($disbursement, function (UmkmDisbursement $locked) use ($head): UmkmDisbursement {
            if ($locked->status === UmkmDisbursementStatus::APPROVED) return $locked;
            if ($locked->status !== UmkmDisbursementStatus::UNDER_REVIEW || ! $this->hasEvent($locked, 'review_passed')) $this->invalidStatus('Pemeriksaan Admin belum selesai.');
            $this->validateReservations($locked);
            $locked->forceFill(['status' => UmkmDisbursementStatus::APPROVED, 'approved_by' => $head->id, 'approved_at' => now()])->save();
            $this->event($locked, $head, 'approved', UmkmDisbursementStatus::UNDER_REVIEW, UmkmDisbursementStatus::APPROVED);
            return $locked;
        });
    }

    public function reject(UmkmDisbursement $disbursement, User $head, string $reason): UmkmDisbursement
    {
        $this->ensureRole($head, 'kepala_bumdes');
        if (blank(trim($reason))) throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);

        return $this->transaction($disbursement, function (UmkmDisbursement $locked) use ($head, $reason): UmkmDisbursement {
            if ($locked->status === UmkmDisbursementStatus::REJECTED) return $locked;
            if ($locked->status !== UmkmDisbursementStatus::UNDER_REVIEW) $this->invalidStatus('Pengajuan tidak dapat ditolak.');
            $allocations = $this->lockedAllocations($locked);
            foreach ($allocations as $allocation) {
                $entitlement = UmkmEntitlement::query()->whereKey($allocation->umkm_entitlement_id)->lockForUpdate()->firstOrFail();
                if ($entitlement->umkm_store_id !== $locked->umkm_store_id || $entitlement->status !== UmkmEntitlementStatus::RESERVED) $this->invalidStatus('Reservation entitlement tidak valid.');
                $entitlement->forceFill(['status' => UmkmEntitlementStatus::AVAILABLE])->save();
                $allocation->forceFill(['entitlement_reservation_lock' => null])->save();
                UmkmEntitlementEvent::firstOrCreate(['idempotency_key' => $this->key('released', $entitlement->id, $locked->id)], [
                    'umkm_entitlement_id' => $entitlement->id, 'actor_id' => $head->id,
                    'event_type' => UmkmEntitlementEventType::RESERVATION_RELEASED,
                    'amount' => $allocation->amount, 'from_status' => UmkmEntitlementStatus::RESERVED,
                    'to_status' => UmkmEntitlementStatus::AVAILABLE, 'notes' => trim($reason),
                ]);
            }
            $locked->forceFill(['status' => UmkmDisbursementStatus::REJECTED, 'rejection_reason' => trim($reason), 'active_request_lock' => null])->save();
            $this->event($locked, $head, 'rejected', UmkmDisbursementStatus::UNDER_REVIEW, UmkmDisbursementStatus::REJECTED, trim($reason));
            return $locked;
        });
    }

    private function transaction(UmkmDisbursement $disbursement, callable $operation): UmkmDisbursement
    {
        return DB::transaction(function () use ($disbursement, $operation): UmkmDisbursement {
            $storeId = $disbursement->umkm_store_id;
            UmkmStore::query()->whereKey($storeId)->lockForUpdate()->firstOrFail();
            $locked = UmkmDisbursement::query()->whereKey($disbursement->id)->lockForUpdate()->firstOrFail();
            return $operation($locked);
        });
    }

    private function validateReservations(UmkmDisbursement $disbursement): void
    {
        if (blank($disbursement->recipient_bank_name) || blank($disbursement->recipient_account_holder_name) || blank($disbursement->recipient_account_number)) $this->invalidStatus('Snapshot rekening tidak lengkap.');
        if (! $disbursement->recipientBankAccount()->lockForUpdate()->first()?->verified_at) $this->invalidStatus('Rekening penerima belum terverifikasi.');
        $total = 0;
        foreach ($this->lockedAllocations($disbursement) as $allocation) {
            $entitlement = UmkmEntitlement::query()->whereKey($allocation->umkm_entitlement_id)->lockForUpdate()->firstOrFail();
            if ($entitlement->umkm_store_id !== $disbursement->umkm_store_id || $entitlement->status !== UmkmEntitlementStatus::RESERVED) $this->invalidStatus('Allocation entitlement tidak valid.');
            $total += $this->minor($allocation->amount);
        }
        if ($total <= 0 || $total !== $this->minor($disbursement->requested_amount)) $this->invalidStatus('Total allocation tidak sesuai nominal pengajuan.');
    }

    private function lockedAllocations(UmkmDisbursement $disbursement)
    {
        $allocations = UmkmDisbursementAllocation::query()->where('umkm_disbursement_id', $disbursement->id)->orderBy('id')->lockForUpdate()->get();
        if ($allocations->isEmpty()) $this->invalidStatus('Pengajuan tidak memiliki allocation.');
        return $allocations;
    }

    private function hasEvent(UmkmDisbursement $disbursement, string $type): bool { return $disbursement->events()->where('event_type', $type)->exists(); }
    private function event(UmkmDisbursement $d, User $actor, string $type, ?string $from, string $to, ?string $notes = null): void
    {
        UmkmDisbursementEvent::firstOrCreate(['idempotency_key' => $this->key($type, $d->id, $d->id)], ['umkm_disbursement_id' => $d->id, 'actor_id' => $actor->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'notes' => $notes]);
    }
    private function key(string $type, int $id, int $scope): string { $h = md5("disbursement:$type:$id:$scope"); return substr($h,0,8).'-'.substr($h,8,4).'-5'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12); }
    private function minor(string $amount): int { preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $m); return isset($m[1]) ? ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0') : -1; }
    private function ensureRole(User $user, string $role): void { if ($user->role !== $role) throw new AuthorizationException('Role tidak berwenang.'); }
    private function invalidStatus(string $message): never { throw ValidationException::withMessages(['disbursement' => $message]); }
}
