@php
    /** Format fixed-scale DECIMAL strings without floating-point conversion. */
    $formatRupiah = static function ($amount): string {
        $amount = trim((string) ($amount ?? '0'));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $parts)) {
            return 'Rp0';
        }

        $integer = ltrim($parts[2], '0') ?: '0';
        $formatted = implode('.', array_map('strrev', array_reverse(str_split(strrev($integer), 3))));
        $fraction = $parts[3] ?? null;

        if ($fraction !== null && rtrim($fraction, '0') !== '') {
            $formatted .= ','.str_pad($fraction, 2, '0');
        }

        return ($parts[1] ?? '').'Rp'.$formatted;
    };

    $statusLabels = [
        'accrued' => 'Hak Terbentuk',
        'eligible' => 'Memenuhi Syarat',
        'available' => 'Tersedia',
        'reserved' => 'Dicadangkan',
        'held' => 'Ditahan',
        'settled' => 'Selesai Dicairkan',
        'reversed' => 'Dibalikkan',
    ];
    $exportFilters = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
@endphp

<x-kepala-bumdes-layout title="Laporan Hak Pendapatan UMKM">
    <x-slot name="header">
        <h2 class="font-semibold text-xl">Laporan Hak Pendapatan UMKM</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <div class="space-y-1 text-sm text-slate-600">
                <p>Laporan ini menampilkan hak pendapatan UMKM yang terbentuk pada periode terpilih, dikelompokkan berdasarkan status saat laporan dibuka.</p>
                <p>Filter tanggal menggunakan tanggal terbentuknya hak pendapatan (<code>accrued_at</code>). Status yang ditampilkan merupakan status saat ini, bukan posisi saldo historis pada tanggal tersebut.</p>
            </div>

            <form method="GET" class="grid gap-3 rounded bg-white p-4 shadow md:grid-cols-5">
                <x-text-input type="date" name="date_from" :value="$filters['date_from']" />
                <x-text-input type="date" name="date_to" :value="$filters['date_to']" />
                <select name="store_id" class="rounded border-slate-300">
                    <option value="">Semua UMKM</option>
                    @foreach ($storeOptions as $store)
                        <option value="{{ $store->id }}" @selected($filters['store_id'] == $store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
                <select name="entitlement_status" class="rounded border-slate-300">
                    <option value="">Semua Status</option>
                    @foreach ($statusLabels as $status => $label)
                        <option value="{{ $status }}" @selected($filters['entitlement_status'] === $status)>{{ $label }}</option>
                    @endforeach
                </select>
                <div>
                    <x-primary-button>Terapkan Filter</x-primary-button>
                    <a class="ml-2" href="{{ route('kepala-bumdes.umkm-financial.entitlements') }}">Reset</a>
                    <a class="ml-2" href="{{ route('kepala-bumdes.umkm-financial.entitlements.export', $exportFilters) }}">Export CSV</a>
                </div>
            </form>

            <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
                <div class="rounded bg-white p-4 shadow text-sm">Jumlah entitlement<strong class="block">{{ $summary->entitlement_count }}</strong></div>
                <div class="rounded bg-white p-4 shadow text-sm">Total nominal diketahui<strong class="block">{{ $formatRupiah($summary->known_amount_total) }}</strong></div>
                <div class="rounded bg-white p-4 shadow text-sm">Nominal belum diketahui<strong class="block">{{ $summary->unknown_amount_count }}</strong></div>
                <div class="rounded bg-white p-4 shadow text-sm">Saldo available<strong class="block">{{ $formatRupiah($statusSummary->get('available')->known_amount_total) }}</strong></div>
                <div class="rounded bg-white p-4 shadow text-sm">Nominal reserved<strong class="block">{{ $formatRupiah($statusSummary->get('reserved')->known_amount_total) }}</strong></div>
                <div class="rounded bg-white p-4 shadow text-sm">Nominal settled<strong class="block">{{ $formatRupiah($statusSummary->get('settled')->known_amount_total) }}</strong></div>
            </div>

            <div class="overflow-x-auto rounded bg-white p-4 shadow">
                <h3 class="font-semibold">Rekap seluruh status</h3>
                <table class="min-w-full text-sm">
                    <thead><tr><th>Status</th><th>Jumlah entitlement</th><th>Total nominal diketahui</th><th>Nominal belum diketahui</th></tr></thead>
                    <tbody>
                        @foreach ($statusLabels as $status => $label)
                            @php($row = $statusSummary->get($status))
                            <tr><td>{{ $label }}</td><td>{{ $row->entitlement_count }}</td><td>{{ $formatRupiah($row->known_amount_total) }}</td><td>{{ $row->unknown_amount_count }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded bg-white p-4 shadow">
                <h3 class="font-semibold">Rekap per UMKM</h3>
                <table class="min-w-full text-sm">
                    <thead><tr><th>UMKM</th><th>Status</th><th>Jumlah entitlement</th><th>Total nominal diketahui</th><th>Nominal belum diketahui</th></tr></thead>
                    <tbody>
                        @forelse ($byStore as $row)
                            <tr><td>{{ $row->store_name }}</td><td>{{ $statusLabels[$row->status] ?? $row->status }}</td><td>{{ $row->entitlement_count }}</td><td>{{ $formatRupiah($row->known_amount_total) }}</td><td>{{ $row->unknown_amount_count }}</td></tr>
                        @empty
                            <tr><td colspan="5">Belum ada entitlement pada filter yang dipilih.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded bg-white p-4 shadow">
                <h3 class="font-semibold">Detail entitlement</h3>
                <table class="min-w-full text-sm">
                    <thead><tr><th>Tanggal terbentuk</th><th>UMKM</th><th>Referensi StoreOrder</th><th>Status</th><th>Nominal snapshot</th><th>Eligible</th><th>Reconciled</th><th>Settled</th></tr></thead>
                    <tbody>
                        @forelse ($details as $entitlement)
                            <tr>
                                <td>{{ $entitlement->accrued_at?->format('d-m-Y H:i') }}</td>
                                <td>{{ $entitlement->umkmStore?->name }}</td>
                                <td>{{ $entitlement->storeOrder?->store_order_number ?? '—' }}</td>
                                <td>{{ $statusLabels[$entitlement->status] ?? $entitlement->status }}</td>
                                <td>{{ $entitlement->source_umkm_amount === null ? 'Nominal belum diketahui' : $formatRupiah($entitlement->source_umkm_amount) }}</td>
                                <td>{{ $entitlement->eligible_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                <td>{{ $entitlement->reconciled_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                <td>{{ $entitlement->settled_at?->format('d-m-Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8">Belum ada entitlement pada filter yang dipilih.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div>{{ $details->links() }}</div>
            </div>
        </div>
    </div>
</x-kepala-bumdes-layout>
