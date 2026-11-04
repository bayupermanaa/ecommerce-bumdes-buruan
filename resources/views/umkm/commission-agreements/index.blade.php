<x-umkm-layout title="Perjanjian Komisi Saya">

    <div class="mx-auto max-w-5xl space-y-6">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <p class="text-sm text-gray-500">Toko UMKM</p>
                <h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $store->name }}</h3>
                @if ($currentAgreement)
                    <div class="mt-5 rounded-md bg-green-50 p-4 text-sm text-green-900"><p class="font-semibold">Perjanjian yang berlaku: {{ $currentAgreement->commission_percentage }}%</p><p class="mt-1">Berlaku {{ $currentAgreement->effective_start_date->format('d-m-Y') }} sampai {{ $currentAgreement->effective_end_date?->format('d-m-Y') ?? 'tanpa tanggal akhir' }}.</p></div>
                @else
                    <div class="mt-5 rounded-md bg-amber-50 p-4 text-sm text-amber-800">Belum ada perjanjian komisi aktif yang berlaku untuk toko ini.</div>
                @endif
            </div>

            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="text-lg font-semibold text-gray-900">Riwayat Perjanjian</h3>
                @if ($agreements->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">Belum ada riwayat perjanjian komisi.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="text-left text-gray-600"><tr><th class="px-3 py-2">Komisi</th><th class="px-3 py-2">Masa berlaku</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Catatan</th></tr></thead><tbody class="divide-y divide-gray-200 text-gray-900">
                            @foreach ($agreements as $agreement)
                                <tr><td class="px-3 py-3 font-semibold">{{ $agreement->commission_percentage }}%</td><td class="px-3 py-3">{{ $agreement->effective_start_date->format('d-m-Y') }} - {{ $agreement->effective_end_date?->format('d-m-Y') ?? '-' }}</td><td class="px-3 py-3">{{ $agreement->statusLabel() }}</td><td class="px-3 py-3">{{ $agreement->notes ?: '-' }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-umkm-layout>
