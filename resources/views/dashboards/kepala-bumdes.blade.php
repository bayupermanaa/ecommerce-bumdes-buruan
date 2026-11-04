<x-kepala-bumdes-layout title="Dashboard Eksekutif">

    @php
        $formatRupiah = static function (mixed $amount): string {
            $number = trim((string) ($amount ?? '0'));

            if (! preg_match('/^-?\d+(?:\.\d+)?$/', $number)) {
                return 'Rp0';
            }

            $negative = str_starts_with($number, '-');
            $number = ltrim($number, '-');
            [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, null);
            $integer = ltrim($integer, '0') ?: '0';

            if ($integer === '0' && ($fraction === null || preg_match('/^0+$/', $fraction))) {
                return 'Rp0';
            }

            $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

            return ($negative ? '-Rp' : 'Rp').$groupedInteger.($fraction !== null ? ','.$fraction : '');
        };
        $entitlementStatusLabels = [
            'accrued' => 'Tercatat',
            'eligible' => 'Layak Direkonsiliasi',
            'available' => 'Tersedia',
            'reserved' => 'Direservasi',
            'held' => 'Ditahan',
            'settled' => 'Telah Diselesaikan',
            'reversed' => 'Dibalik',
        ];
        $disbursementStatusLabels = [
            'requested' => 'Diajukan',
            'under_review' => 'Dalam Pemeriksaan',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'cancelled' => 'Dibatalkan',
            'transfer_failed' => 'Transfer Gagal',
            'paid' => 'Dibayar',
        ];
    @endphp

    <div class="space-y-8">
            <div class="rounded-3xl bg-slate-950 p-6 text-white shadow-xl sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Ringkasan kinerja BUMDes</p>
                <h2 class="mt-3 text-3xl font-bold sm:text-4xl">Pantau bisnis dalam satu pandangan.</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">Monitoring read-only berbasis data transaksi tersimpan untuk mendukung keputusan operasional dan keuangan.</p>
                <p class="mt-5 text-sm text-slate-400">{{ auth()->user()->name }} · Kepala BUMDes</p>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4"><p class="text-sm text-slate-300">UMKM Terdaftar</p><p class="mt-1 text-2xl font-bold">{{ number_format($metrics['registered_stores']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">UMKM Disetujui</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['approved_stores']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Produk Disetujui</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['approved_products']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Seluruh Pesanan</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['total_orders']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Pesanan Dalam Proses</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['in_fulfillment_orders']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Pesanan Selesai</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['completed_orders']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Pesanan Dibatalkan</p><p class="mt-1 text-2xl font-semibold">{{ number_format($metrics['cancelled_orders']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Pembayaran Terkonfirmasi</p><p class="mt-1 text-2xl font-semibold">{{ $formatRupiah($metrics['confirmed_payment_total']) }}</p></div>
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">Komisi Snapshot (Pesanan Paid)</p><p class="mt-1 text-2xl font-semibold">{{ $formatRupiah($metrics['commission_snapshot_total']) }}</p></div>
                    </div>

                    <p class="text-xs text-gray-500">Komisi adalah snapshot per toko pada pesanan dengan pembayaran terkonfirmasi; nilainya bukan kas BUMDes maupun saldo yang siap dicairkan.</p>

                    <section class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-5 space-y-4" aria-labelledby="monitoring-hak-umkm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 id="monitoring-hak-umkm" class="text-lg font-semibold text-gray-900">Monitoring Hak UMKM</h3>
                                <p class="mt-1 text-sm text-gray-600">Status entitlement saat ini, bukan saldo historis pada tanggal tertentu.</p>
                            </div>
                            <a href="{{ route('kepala-bumdes.umkm-financial.entitlements') }}" class="inline-flex items-center self-start rounded-md bg-emerald-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-emerald-600">Laporan Hak UMKM</a>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-md border border-emerald-200 bg-white p-3"><p class="text-xs text-gray-500">Total Entitlement</p><p class="mt-1 text-xl font-semibold">{{ number_format($entitlementMonitoring['summary']['entitlement_count']) }}</p></div>
                            <div class="rounded-md border border-emerald-200 bg-white p-3"><p class="text-xs text-gray-500">Nominal Diketahui</p><p class="mt-1 text-xl font-semibold">{{ $formatRupiah($entitlementMonitoring['summary']['known_amount_total']) }}</p></div>
                            <div class="rounded-md border border-emerald-200 bg-white p-3"><p class="text-xs text-gray-500">Nominal Belum Diketahui</p><p class="mt-1 text-xl font-semibold">{{ number_format($entitlementMonitoring['summary']['unknown_amount_count']) }}</p></div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($entitlementStatusLabels as $status => $label)
                                @php($statusSummary = $entitlementMonitoring['statuses'][$status])
                                <div class="rounded-md border border-emerald-100 bg-white p-3">
                                    <p class="text-xs font-medium text-gray-700">{{ $label }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ number_format($statusSummary['entitlement_count']) }} entitlement</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ $formatRupiah($statusSummary['known_amount_total']) }}</p>
                                    @if ($statusSummary['unknown_amount_count'] > 0)
                                        <p class="mt-1 text-xs text-amber-700">{{ number_format($statusSummary['unknown_amount_count']) }} nominal belum diketahui</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-lg border border-blue-200 bg-blue-50/50 p-5 space-y-4" aria-labelledby="monitoring-pencairan-umkm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 id="monitoring-pencairan-umkm" class="text-lg font-semibold text-gray-900">Monitoring Pencairan UMKM</h3>
                                <p class="mt-1 text-sm text-gray-600">Pengelompokan mengikuti status saat ini; periode laporan lengkap berbasis cohort tanggal pengajuan.</p>
                            </div>
                            <a href="{{ route('kepala-bumdes.umkm-financial.disbursements') }}" class="inline-flex items-center self-start rounded-md bg-blue-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-blue-600">Laporan Pencairan UMKM</a>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="rounded-md border border-blue-200 bg-white p-3"><p class="text-xs text-gray-500">Seluruh Pengajuan</p><p class="mt-1 text-xl font-semibold">{{ number_format($disbursementMonitoring['summary']['disbursement_count']) }}</p></div>
                            <div class="rounded-md border border-blue-200 bg-white p-3"><p class="text-xs text-gray-500">Nilai Historis Pengajuan</p><p class="mt-1 text-xl font-semibold">{{ $formatRupiah($disbursementMonitoring['summary']['historical_requested_total']) }}</p></div>
                            <div class="rounded-md border border-blue-200 bg-white p-3"><p class="text-xs text-gray-500">Pencairan Final (Paid)</p><p class="mt-1 text-sm font-semibold">{{ number_format($disbursementMonitoring['summary']['paid_count']) }} pengajuan</p><p class="mt-1 text-sm font-semibold">{{ $formatRupiah($disbursementMonitoring['summary']['paid_total']) }}</p></div>
                            <div class="rounded-md border border-blue-200 bg-white p-3"><p class="text-xs text-gray-500">Pengajuan Berjalan</p><p class="mt-1 text-sm font-semibold">{{ number_format($disbursementMonitoring['summary']['in_progress_count']) }} pengajuan</p><p class="mt-1 text-sm font-semibold">{{ $formatRupiah($disbursementMonitoring['summary']['in_progress_total']) }}</p></div>
                            <div class="rounded-md border border-blue-200 bg-white p-3"><p class="text-xs text-gray-500">Riwayat Non-Final</p><p class="mt-1 text-sm font-semibold">{{ number_format($disbursementMonitoring['summary']['non_final_count']) }} pengajuan</p><p class="mt-1 text-sm font-semibold">{{ $formatRupiah($disbursementMonitoring['summary']['non_final_total']) }}</p></div>
                        </div>

                        <p class="text-xs text-gray-600">Hanya status paid yang berarti pencairan final. Status approved belum berarti dana telah dibayarkan; transfer gagal, ditolak, dan dibatalkan bukan pencairan berhasil.</p>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($disbursementStatusLabels as $status => $label)
                                @php($statusSummary = $disbursementMonitoring['statuses'][$status])
                                <div class="rounded-md border border-blue-100 bg-white p-3">
                                    <p class="text-xs font-medium text-gray-700">{{ $label }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ number_format($statusSummary['disbursement_count']) }} pengajuan</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ $formatRupiah($statusSummary['requested_amount_total']) }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <div>
                        <a href="{{ route('kepala-bumdes.transactions.index') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            Laporan Transaksi
                        </a>
                        <a href="{{ route('kepala-bumdes.commission-report.index') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Laporan Komisi</a>
                        <a href="{{ route('kepala-bumdes.umkm-financial.entitlements') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Laporan Hak UMKM</a>
                        <a href="{{ route('kepala-bumdes.umkm-financial.disbursements') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Laporan Pencairan UMKM</a>
                        <a href="{{ route('kepala-bumdes.stores.index') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            Laporan Per UMKM
                        </a>
                        <a href="{{ route('kepala-bumdes.disbursements.index') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            Persetujuan Pencairan
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-kepala-bumdes-layout>
