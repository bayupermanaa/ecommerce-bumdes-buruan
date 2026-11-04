@php
    /** Format DECIMAL strings for presentation without converting them to floats. */
    $formatRupiah = static function ($amount): string {
        $amount = trim((string) ($amount ?? '0'));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $parts)) {
            return 'Rp0';
        }

        $integer = ltrim($parts[2], '0') ?: '0';
        $groupedInteger = implode('.', array_map('strrev', array_reverse(str_split(strrev($integer), 3))));
        $fraction = $parts[3] ?? null;

        if ($fraction !== null && rtrim($fraction, '0') !== '') {
            $groupedInteger .= ','.str_pad($fraction, 2, '0');
        }

        return ($parts[1] ?? '').'Rp'.$groupedInteger;
    };

    $formatPercentage = static function ($percentage): string {
        $percentage = trim((string) ($percentage ?? '0'));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $percentage, $parts)) {
            return '0%';
        }

        $integer = ltrim($parts[2], '0') ?: '0';
        $fraction = rtrim($parts[3] ?? '', '0');

        return ($parts[1] ?? '').$integer.($fraction !== '' ? ','.$fraction : '').'%';
    };

    $exportFilters = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
@endphp

<x-kepala-bumdes-layout title="Laporan Komisi BUMDes"><div class="mx-auto max-w-7xl space-y-6">
<p class="text-sm text-slate-600">Ringkasan komisi berdasarkan transaksi dengan pembayaran terverifikasi. Periode menggunakan tanggal pembayaran yang telah dikonfirmasi, bukan tanggal pembuatan pesanan.</p>
<form method="GET" class="grid gap-3 rounded bg-white p-4 shadow md:grid-cols-4"><x-text-input type="date" name="date_from" :value="$filters['date_from']??''"/><x-text-input type="date" name="date_to" :value="$filters['date_to']??''"/><select name="store_id" class="rounded border-slate-300"><option value="">Semua UMKM</option>@foreach($storeOptions as $store)<option value="{{$store->id}}" @selected(($filters['store_id']??'')==$store->id)>{{$store->name}}</option>@endforeach</select><div><x-primary-button>Terapkan Filter</x-primary-button><a class="ml-2" href="{{route('kepala-bumdes.commission-report.index')}}">Reset</a><a class="ml-2" href="{{ route('kepala-bumdes.commission-report.export', $exportFilters) }}">Export CSV</a></div></form>
<div class="grid gap-3 md:grid-cols-5">@foreach(['transaction_total'=>'Nilai transaksi toko','commission_total'=>'Komisi BUMDes','estimated_umkm_total'=>'Estimasi hak UMKM','transaction_count'=>'Transaksi','store_count'=>'UMKM'] as $key=>$label)<div class="rounded bg-white p-4 shadow text-sm">{{$label}}<strong class="block">{{ str_ends_with($key, 'total') ? $formatRupiah($summary->$key) : $summary->$key }}</strong></div>@endforeach</div>
<div class="overflow-x-auto rounded bg-white p-4 shadow"><h3 class="font-semibold">Rekap per UMKM</h3><table class="min-w-full text-sm"><thead><tr><th>UMKM</th><th>Transaksi</th><th>Nilai</th><th>Komisi</th><th>Estimasi hak</th></tr></thead><tbody>@forelse($byStore as $row)<tr><td>{{$row->name}}</td><td>{{$row->transaction_count}}</td><td>{{ $formatRupiah($row->transaction_total) }}</td><td>{{ $formatRupiah($row->commission_total) }}</td><td>{{ $formatRupiah($row->estimated_umkm_total) }}</td></tr>@empty<tr><td colspan="5">Belum ada transaksi terverifikasi.</td></tr>@endforelse</tbody></table></div>
<div class="overflow-x-auto rounded bg-white p-4 shadow"><h3 class="font-semibold">Detail transaksi</h3><table class="min-w-full text-sm"><thead><tr><th>Tanggal bayar</th><th>Pesanan</th><th>UMKM</th><th>Nilai</th><th>%</th><th>Komisi</th><th>Hak UMKM</th><th>Status</th></tr></thead><tbody>@forelse($details as $row)<tr><td>{{$row->order->payment_confirmed_at?->format('d-m-Y')}}</td><td>{{$row->order->order_number}}</td><td>{{$row->umkmStore->name}}</td><td>{{ $formatRupiah($row->total) }}</td><td>{{ $formatPercentage($row->commission_percentage) }}</td><td>{{ $formatRupiah($row->commission_amount) }}</td><td>{{ $formatRupiah($row->umkm_amount) }}</td><td>{{$row->order->payment_status}} / {{$row->order->order_status}}</td></tr>@empty<tr><td colspan="8">Belum ada transaksi terverifikasi.</td></tr>@endforelse</tbody></table><div>{{$details->links()}}</div></div>
</div></div></x-kepala-bumdes-layout>
