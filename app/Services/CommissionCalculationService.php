<?php

namespace App\Services;

use App\Models\CommissionAgreement;
use App\Models\UmkmStore;
use Carbon\CarbonInterface;

class CommissionCalculationService
{
    /**
     * Find the active agreement applicable to a store on a transaction date.
     */
    public function agreementForStore(UmkmStore|int $store, CarbonInterface|string|null $date = null): ?CommissionAgreement
    {
        $storeId = $store instanceof UmkmStore ? $store->id : $store;
        $effectiveDate = $date instanceof CarbonInterface
            ? $date->toDateString()
            : ($date ?? today()->toDateString());

        return CommissionAgreement::query()
            ->where('umkm_store_id', $storeId)
            ->where('status', 'active')
            ->whereDate('effective_start_date', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate): void {
                $query->whereNull('effective_end_date')
                    ->orWhereDate('effective_end_date', '>=', $effectiveDate);
            })
            ->orderByDesc('effective_start_date')
            ->first();
    }

    /**
     * Calculate commission from a store subtotal only. Null means no agreement applies.
     *
     * @return array{agreement: CommissionAgreement, subtotal: string, commission_amount: string, umkm_amount: string}|null
     */
    public function calculateForStore(UmkmStore|int $store, string $subtotal, CarbonInterface|string|null $date = null): ?array
    {
        $agreement = $this->agreementForStore($store, $date);

        if (! $agreement) {
            return null;
        }

        $subtotalInMinorUnits = $this->toMinorUnits($subtotal);
        $percentageInBasisPoints = $this->percentageToBasisPoints($agreement->commission_percentage);
        // Round half up at the smallest currency unit: subtotal x percentage / 100.
        $commissionInMinorUnits = intdiv(($subtotalInMinorUnits * $percentageInBasisPoints) + 5000, 10000);

        return [
            'agreement' => $agreement,
            'subtotal' => $this->fromMinorUnits($subtotalInMinorUnits),
            'commission_amount' => $this->fromMinorUnits($commissionInMinorUnits),
            'umkm_amount' => $this->fromMinorUnits($subtotalInMinorUnits - $commissionInMinorUnits),
        ];
    }

    /**
     * Convert a positive two-decimal money string to integer minor units without float arithmetic.
     */
    private function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new \InvalidArgumentException('Subtotal must be a non-negative decimal with up to two decimal places.');
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    /**
     * Convert a two-decimal percentage to basis points (12.50% becomes 1250).
     */
    private function percentageToBasisPoints(string $percentage): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Format integer minor units for storage-compatible monetary output.
     */
    private function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
