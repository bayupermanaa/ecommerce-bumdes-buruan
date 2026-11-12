@php($layout = auth()->user()->role === 'penjual' ? 'umkm-layout' : 'catalog-layout')
<x-dynamic-component :component="$layout" title="Chat">
    <section class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-semibold uppercase tracking-widest text-emerald-700">Komunikasi marketplace</p><h1 class="mt-1 text-3xl font-bold text-slate-950">Percakapan</h1></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800">Belum dibaca: {{ $unread }}</span></div>
        <div class="mt-6 space-y-3">
            @forelse ($conversations as $conversation)
                @php($other = auth()->user()->role === 'penjual' ? $conversation->customer : $conversation->store)
                <a href="{{ route('chat.show', $conversation) }}" class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow-md"><div class="flex items-center justify-between gap-4"><div><h2 class="font-semibold text-slate-950">{{ $other->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ auth()->user()->role === 'penjual' ? $conversation->store->name : 'Toko Penjual' }}</p></div><time class="text-xs text-slate-500">{{ $conversation->updated_at->format('d/m/Y H:i') }}</time></div></a>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center"><h2 class="text-lg font-semibold text-slate-900">Belum ada percakapan</h2><p class="mt-2 text-sm text-slate-600">Mulai chat dari halaman detail produk untuk menghubungi Penjual.</p></div>
            @endforelse
        </div>
        <div class="mt-6">{{ $conversations->links() }}</div>
    </section>
</x-dynamic-component>
