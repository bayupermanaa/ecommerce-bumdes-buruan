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
        'requested' => 'Diajukan',
        'under_review' => 'Dalam Peninjauan',
        'approved' => 'Disetujui',
        'paid' => 'Berhasil Dicairkan',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
        'transfer_failed' => 'Transfer Gagal',
    ];
    $exportFilters = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
@endphp

<x-kepala-bumdes-layout title="Laporan Pencairan Penjual">
    <x-slot name="header"><h2 class="font-semibold text-xl">Laporan Pencairan Penjual</h2></x-slot>

    <div class="py-12"><div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <div class="space-y-1 text-sm text-slate-600">
            <p>Laporan ini menampilkan pengajuan pencairan Penjual berdasarkan tanggal pengajuan, dikelompokkan menurut status saat laporan dibuka.</p>
            <p>Filter tanggal menggunakan tanggal pengajuan (<code>requested_at</code>). Status pencairan merupakan status saat ini. Laporan ini bukan laporan arus kas keluar berdasarkan tanggal transfer berhasil.</p>
        </div>

        <form method="GET" class="grid gap-3 rounded bg-white p-4 shadow md:grid-cols-5">
            <x-text-input type="date" name="date_from" :value="$filters['date_from']" />
            <x-text-input type="date" name="date_to" :value="$filters['date_to']" />
            <select name="store_id" class="rounded border-slate-300"><option value="">Semua Penjual</option>@foreach ($storeOptions as $store)<option value="{{ $store->id }}" @selected($filters['store_id'] == $store->id)>{{ $store->name }}</option>@endforeach</select>
            <select name="disbursement_status" class="rounded border-slate-300"><option value="">Semua Status</option>@foreach ($statusLabels as $status => $label)<option value="{{ $status }}" @selected($filters['disbursement_status'] === $status)>{{ $label }}</option>@endforeach</select>
            <div><x-primary-button>Terapkan Filter</x-primary-button><a class="ml-2" href="{{ route('kepala-bumdes.umkm-financial.disbursements') }}">Reset</a><a class="ml-2" href="{{ route('kepala-bumdes.umkm-financial.disbursements.export', $exportFilters) }}">Export CSV</a></div>
        </form>

        <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded bg-white p-4 shadow text-sm">Total pengajuan<strong class="block">{{ $summary->disbursement_count }}</strong></div>
            <div class="rounded bg-white p-4 shadow text-sm">Nilai historis pengajuan<strong class="block">{{ $formatRupiah($summary->historical_requested_total) }}</strong></div>
            <div class="rounded bg-white p-4 shadow text-sm">Pengajuan berhasil<strong class="block">{{ $summary->paid_count }}</strong></div>
            <div class="rounded bg-white p-4 shadow text-sm">Total berhasil dicairkan<strong class="block">{{ $formatRupiah($summary->paid_total) }}</strong></div>
            <div class="rounded bg-white p-4 shadow text-sm">Pengajuan berjalan<strong class="block">{{ $summary->in_progress_count }}</strong></div>
            <div class="rounded bg-white p-4 shadow text-sm">Nominal pengajuan berjalan<strong class="block">{{ $formatRupiah($summary->in_progress_total) }}</strong></div>
        </div>

        <div class="overflow-x-auto rounded bg-white p-4 shadow"><h3 class="font-semibold">Rekap status pencairan</h3><table class="min-w-full text-sm"><thead><tr><th>Status</th><th>Jumlah pengajuan</th><th>Total nominal pengajuan</th></tr></thead><tbody>@foreach ($statusLabels as $status => $label)@php($row = $statusSummary->get($status))<tr><td>{{ $label }}</td><td>{{ $row->disbursement_count }}</td><td>{{ $formatRupiah($row->requested_amount_total) }}</td></tr>@endforeach</tbody></table></div>

        <div class="overflow-x-auto rounded bg-white p-4 shadow"><h3 class="font-semibold">Rekap per Penjual</h3><table class="min-w-full text-sm"><thead><tr><th>Penjual</th><th>Status</th><th>Jumlah pengajuan</th><th>Total nominal pengajuan</th></tr></thead><tbody>@forelse ($byStore as $row)<tr><td>{{ $row->store_name }}</td><td>{{ $statusLabels[$row->status] ?? $row->status }}</td><td>{{ $row->disbursement_count }}</td><td>{{ $formatRupiah($row->requested_amount_total) }}</td></tr>@empty<tr><td colspan="4">Belum ada pengajuan pencairan pada filter yang dipilih.</td></tr>@endforelse</tbody></table></div>

        <div class="overflow-x-auto rounded bg-white p-4 shadow"><h3 class="font-semibold">Detail pencairan</h3><table class="min-w-full text-sm"><thead><tr><th>ID</th><th>Penjual</th><th>Diajukan</th><th>Nominal</th><th>Status</th><th>Ditinjau</th><th>Disetujui</th><th>Transfer dicatat</th><th>Transfer diverifikasi</th><th>Berhasil dicairkan</th><th>Kegagalan dikonfirmasi</th></tr></thead><tbody>@forelse ($details as $disbursement)<tr><td>{{ $disbursement->id }}</td><td>{{ $disbursement->umkmStore?->name }}</td><td>{{ $disbursement->requested_at?->format('d-m-Y H:i') }}</td><td>{{ $formatRupiah($disbursement->requested_amount) }}</td><td>{{ $statusLabels[$disbursement->status] ?? $disbursement->status }}</td><td>{{ $disbursement->reviewed_at?->format('d-m-Y H:i') ?? '—' }}</td><td>{{ $disbursement->approved_at?->format('d-m-Y H:i') ?? '—' }}</td><td>{{ $disbursement->transfer_recorded_at?->format('d-m-Y H:i') ?? '—' }}</td><td>{{ $disbursement->transfer_verified_at?->format('d-m-Y H:i') ?? '—' }}</td><td>{{ $disbursement->paid_at?->format('d-m-Y H:i') ?? '—' }}</td><td>{{ $disbursement->transfer_failure_verified_at?->format('d-m-Y H:i') ?? '—' }}</td></tr>@empty<tr><td colspan="11">Belum ada pengajuan pencairan pada filter yang dipilih.</td></tr>@endforelse</tbody></table><div>{{ $details->links() }}</div></div>
    </div></div>
</x-kepala-bumdes-layout>
