<x-admin-layout title="Dashboard Admin BUMDes">
    <div class="mx-auto max-w-7xl space-y-8">
        <section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-medium text-slate-500">Selamat datang kembali, {{ auth()->user()->name }}.</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Ringkasan operasional</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Pantau proses pembayaran, hak UMKM, dan pekerjaan verifikasi dari satu tempat.</p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Akses Admin BUMDes aktif</span>
        </section>

        <section aria-labelledby="entitlement-summary-heading">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 id="entitlement-summary-heading" class="text-base font-bold text-slate-900">Monitoring hak UMKM</h3>
                    <p class="mt-1 text-sm text-slate-500">Ringkasan status entitlement saat ini.</p>
                </div>
                <a href="{{ route('admin.umkm-entitlements.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Buka rekonsiliasi <span aria-hidden="true">→</span></a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Terbentuk</p><p class="mt-3 text-xl font-bold text-slate-950">Rp {{ number_format((float) $entitlementSummary['accrued'], 2, ',', '.') }}</p><p class="mt-2 text-xs text-slate-400">Accrued</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Eligible</p><p class="mt-3 text-xl font-bold text-slate-950">Rp {{ number_format((float) $entitlementSummary['eligible'], 2, ',', '.') }}</p><p class="mt-2 text-xs text-amber-600">Menunggu rekonsiliasi</p></div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 shadow-sm"><p class="text-sm font-medium text-emerald-700">Tersedia</p><p class="mt-3 text-xl font-bold text-emerald-950">Rp {{ number_format((float) $entitlementSummary['available'], 2, ',', '.') }}</p><p class="mt-2 text-xs text-emerald-700">Available</p></div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm"><p class="text-sm font-medium text-amber-700">Ditahan</p><p class="mt-3 text-xl font-bold text-amber-950">{{ $entitlementSummary['held_count'] }} <span class="text-sm font-semibold">entitlement</span></p><p class="mt-2 text-xs text-amber-700">Nominal tercatat: Rp {{ number_format((float) $entitlementSummary['held_known_amount'], 2, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Perlu rekonsiliasi</p><p class="mt-3 text-xl font-bold text-slate-950">{{ $entitlementSummary['eligible_count'] }}</p><p class="mt-2 text-xs text-slate-400">Entitlement eligible</p></div>
            </div>
        </section>

        <section aria-labelledby="quick-actions-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                <div><h3 id="quick-actions-heading" class="text-base font-bold text-slate-900">Akses cepat</h3><p class="mt-1 text-sm text-slate-500">Pilih modul berdasarkan pekerjaan yang ingin dilakukan.</p></div>
                <span class="text-xs font-medium text-slate-400">Gunakan menu samping untuk navigasi lengkap</span>
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach([
                    ['admin.payments.index', 'Verifikasi Pembayaran', 'Periksa transfer dan penerimaan tunai.', 'bg-blue-50 text-blue-700'],
                    ['admin.umkm-stores.index', 'Verifikasi Toko UMKM', 'Tinjau pengajuan toko baru.', 'bg-slate-100 text-slate-700'],
                    ['admin.products.index', 'Verifikasi Produk', 'Kelola produk yang menunggu pemeriksaan.', 'bg-slate-100 text-slate-700'],
                    ['admin.product-revisions.index', 'Revisi Produk', 'Tinjau perubahan dari pemilik UMKM.', 'bg-slate-100 text-slate-700'],
                    ['admin.commission-agreements.index', 'Perjanjian Komisi', 'Kelola konfigurasi perjanjian komisi.', 'bg-emerald-50 text-emerald-700'],
                    ['admin.disbursements.index', 'Pencairan UMKM', 'Periksa proses dan bukti pencairan.', 'bg-amber-50 text-amber-700'],
                    ['admin.financial-audit.index', 'Audit Keuangan', 'Tinjau exception keuangan read-only.', 'bg-rose-50 text-rose-700'],
                ] as [$routeName, $label, $description, $badgeClass])
                    <a href="{{ route($routeName) }}" class="group rounded-xl border border-slate-200 p-4 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
                        <div class="flex items-start justify-between gap-3"><span class="rounded-lg px-2.5 py-1 text-xs font-bold {{ $badgeClass }}">Buka</span><span class="text-lg text-slate-300 transition group-hover:translate-x-1 group-hover:text-blue-600" aria-hidden="true">→</span></div>
                        <h4 class="mt-4 text-sm font-bold text-slate-900">{{ $label }}</h4>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $description }}</p>
                    </a>
                @endforeach
            </div>
            <div class="mt-6 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-2">
                <a href="{{ route('admin.bumdes-bank-accounts.index') }}" class="rounded-xl bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Kelola rekening resmi BUMDes <span class="float-right">→</span></a>
                <a href="{{ route('admin.recipient-bank-accounts.index') }}" class="rounded-xl bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Verifikasi rekening UMKM <span class="float-right">→</span></a>
            </div>
        </section>
    </div>
</x-admin-layout>
