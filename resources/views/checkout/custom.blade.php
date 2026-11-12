<x-catalog-layout title="Checkout Pesanan Custom">
    <section class="custom-checkout mx-auto max-w-3xl px-3 py-6 sm:px-6 sm:py-8 lg:px-8">
        <a href="{{ route('chat.show', $offer->conversation) }}" class="text-sm font-semibold text-slate-600">&larr; Kembali ke chat</a>
        <div class="mt-5 min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h1 class="text-2xl font-bold text-slate-950">Checkout Pesanan Custom</h1>
            <div class="mt-5 rounded-xl bg-emerald-50 p-4"><h2 class="font-semibold">{{ $offer->product_name_snapshot }}</h2><p class="text-sm text-slate-600">{{ $offer->variant_label_snapshot ?: 'Tanpa variasi' }}</p><p class="mt-2 text-sm">{{ data_get($offer->custom_specifications, 'notes', 'Tidak ada catatan spesifikasi.') }}</p><div class="mt-3 flex justify-between text-sm"><span>Jumlah {{ $offer->quantity }} × Rp {{ number_format((float) $offer->unit_price, 0, ',', '.') }}</span><strong>Rp {{ number_format((float) $offer->total_price, 0, ',', '.') }}</strong></div></div>
            <form method="POST" action="{{ route('checkout.custom.store', $offer) }}" class="mt-6 space-y-4">@csrf<input type="hidden" name="fulfillment_method" value="pickup"><div><label class="text-sm font-medium">Nama penerima</label><input name="recipient_name" required value="{{ old('recipient_name', auth()->user()->name) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div><div><label class="text-sm font-medium">Nomor telepon</label><input name="recipient_phone" required value="{{ old('recipient_phone', auth()->user()->phone) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div><button class="w-full rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white">Buat Pesanan Custom</button></form>
        </div>
    </section>
</x-catalog-layout>
