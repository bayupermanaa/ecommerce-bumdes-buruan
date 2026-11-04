<x-admin-layout title="Detail Perjanjian Komisi">

    <div>
        <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-sm text-gray-500">Toko UMKM</p><h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $agreement->umkmStore->name }}</h3></div>
                    <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $agreement->status === 'active' ? 'bg-green-100 text-green-800' : (($agreement->status === 'ended' || $agreement->status === 'cancelled') ? 'bg-gray-200 text-gray-700' : 'bg-amber-100 text-amber-800') }}">{{ $agreement->statusLabel() }}</span>
                </div>
                <dl class="mt-6 grid gap-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">Komisi BUMDes</dt><dd class="mt-1 text-lg font-bold text-gray-900">{{ $agreement->commission_percentage }}%</dd></div>
                    <div><dt class="text-gray-500">Masa berlaku</dt><dd class="mt-1 font-medium text-gray-900">{{ $agreement->effective_start_date->format('d-m-Y') }} sampai {{ $agreement->effective_end_date?->format('d-m-Y') ?? 'tanpa tanggal akhir' }}</dd></div>
                    <div><dt class="text-gray-500">Dicatat oleh</dt><dd class="mt-1 font-medium text-gray-900">{{ $agreement->creator->name }}</dd></div>
                    <div><dt class="text-gray-500">Aktivasi / berakhir</dt><dd class="mt-1 font-medium text-gray-900">{{ $agreement->activated_at?->format('d-m-Y H:i') ?? '-' }} / {{ $agreement->ended_at?->format('d-m-Y H:i') ?? '-' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-gray-500">Catatan</dt><dd class="mt-1 whitespace-pre-line text-gray-900">{{ $agreement->notes ?: '-' }}</dd></div>
                </dl>

                @if ($agreement->status === 'draft')
                    <div class="mt-6 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('admin.commission-agreements.activate', $agreement) }}" onsubmit="return confirm('Aktifkan perjanjian ini?')">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-md bg-green-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-green-800">Aktifkan Perjanjian</button>
                        </form>
                        <form method="POST" action="{{ route('admin.commission-agreements.cancel', $agreement) }}" onsubmit="return confirm('Batalkan draft perjanjian ini?')">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800">Batalkan Draft</button>
                        </form>
                    </div>
                @elseif ($agreement->status === 'active')
                    <form method="POST" action="{{ route('admin.commission-agreements.end', $agreement) }}" class="mt-6 flex flex-wrap items-end gap-3" onsubmit="return confirm('Akhiri perjanjian ini?')">
                        @csrf
                        @method('PATCH')
                        <div><x-input-label for="effective_end_date" value="Tanggal Berakhir" /><x-text-input id="effective_end_date" name="effective_end_date" type="date" max="{{ today()->toDateString() }}" value="{{ old('effective_end_date', today()->toDateString()) }}" class="mt-1" required /></div>
                        <x-danger-button>Akhiri Perjanjian</x-danger-button>
                    </form>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-gray-900">Riwayat Perjanjian Toko</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-gray-600"><tr><th class="px-3 py-2">Komisi</th><th class="px-3 py-2">Masa berlaku</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Dicatat oleh</th></tr></thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($history as $entry)
                                <tr><td class="px-3 py-3"><a href="{{ route('admin.commission-agreements.show', $entry) }}" class="font-medium text-indigo-700 hover:text-indigo-900">{{ $entry->commission_percentage }}%</a></td><td class="px-3 py-3">{{ $entry->effective_start_date->format('d-m-Y') }} - {{ $entry->effective_end_date?->format('d-m-Y') ?? '-' }}</td><td class="px-3 py-3">{{ $entry->statusLabel() }}</td><td class="px-3 py-3">{{ $entry->creator->name }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
