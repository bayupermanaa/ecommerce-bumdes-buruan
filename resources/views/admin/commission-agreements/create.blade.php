<x-admin-layout title="Buat Perjanjian Komisi">

    <div>
        <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-600">Toko Penjual</p>
                <p class="mt-1 font-semibold text-gray-900">{{ $store->name }}</p>
                <form method="POST" action="{{ route('admin.commission-agreements.store') }}" class="mt-6 space-y-5" onsubmit="if (this.dataset.submitting === 'true') return false; this.dataset.submitting = 'true'; const button = this.querySelector('[data-save-agreement]'); if (button) { button.disabled = true; button.setAttribute('aria-disabled', 'true'); button.textContent = 'Menyimpan...'; } return true;">
                    @csrf
                    <input type="hidden" name="umkm_store_id" value="{{ $store->id }}">
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                    <div>
                        <x-input-label for="commission_percentage" value="Persentase Komisi BUMDes (%)" />
                        <x-text-input id="commission_percentage" name="commission_percentage" type="number" min="0" max="100" step="0.01" value="{{ old('commission_percentage') }}" class="mt-1 block w-full" required />
                        <x-input-error class="mt-2" :messages="$errors->get('commission_percentage')" />
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><x-input-label for="effective_start_date" value="Mulai Berlaku" /><x-text-input id="effective_start_date" name="effective_start_date" type="date" value="{{ old('effective_start_date') }}" class="mt-1 block w-full" required /><x-input-error class="mt-2" :messages="$errors->get('effective_start_date')" /></div>
                        <div><x-input-label for="effective_end_date" value="Berakhir (opsional)" /><x-text-input id="effective_end_date" name="effective_end_date" type="date" value="{{ old('effective_end_date') }}" class="mt-1 block w-full" /><x-input-error class="mt-2" :messages="$errors->get('effective_end_date')" /></div>
                    </div>
                    <div>
                        <x-input-label for="notes" value="Catatan Perjanjian" />
                        <textarea id="notes" name="notes" rows="5" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('notes')" />
                    </div>
                    <p class="text-sm text-gray-600">Perjanjian disimpan sebagai draft. Aktifkan setelah masa berlaku tidak bertabrakan dengan perjanjian aktif lain.</p>
                    <div class="flex flex-wrap gap-3"><x-primary-button data-save-agreement>Simpan Draft</x-primary-button><a href="{{ route('admin.commission-agreements.index') }}" class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Batal</a></div>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
