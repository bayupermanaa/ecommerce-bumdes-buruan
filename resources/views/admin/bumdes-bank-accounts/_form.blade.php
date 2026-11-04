<div class="space-y-5">
    <div>
        <label for="bank_name" class="block text-sm font-medium text-gray-700">Nama bank</label>
        <input id="bank_name" name="bank_name" value="{{ old('bank_name', $account->bank_name ?? '') }}" maxlength="255" required {{ !empty($hasPaymentHistory) ? 'readonly' : '' }} class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm {{ !empty($hasPaymentHistory) ? 'bg-gray-100 text-gray-600' : 'focus:border-indigo-500 focus:ring-indigo-500' }}">
        @error('bank_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="account_holder_name" class="block text-sm font-medium text-gray-700">Nama pemilik rekening</label>
        <input id="account_holder_name" name="account_holder_name" value="{{ old('account_holder_name', $account->account_holder_name ?? '') }}" maxlength="255" required {{ !empty($hasPaymentHistory) ? 'readonly' : '' }} class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm {{ !empty($hasPaymentHistory) ? 'bg-gray-100 text-gray-600' : 'focus:border-indigo-500 focus:ring-indigo-500' }}">
        @error('account_holder_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="account_number" class="block text-sm font-medium text-gray-700">Nomor rekening</label>
        <input id="account_number" name="account_number" value="{{ old('account_number', $account->account_number ?? '') }}" inputmode="numeric" pattern="[0-9]+" maxlength="255" required {{ !empty($hasPaymentHistory) ? 'readonly' : '' }} class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm {{ !empty($hasPaymentHistory) ? 'bg-gray-100 text-gray-600' : 'focus:border-indigo-500 focus:ring-indigo-500' }}">
        <p class="mt-1 text-xs text-gray-500">Disimpan sebagai teks agar angka nol di awal tetap terjaga.</p>
        @error('account_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    @if (! isset($account))
        <div>
            <label for="status" class="block text-sm font-medium text-gray-700">Status awal</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="active" @selected(old('status', 'active') === 'active')>Aktif</option>
                <option value="inactive" @selected(old('status') === 'inactive')>Nonaktif</option>
            </select>
            @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    @endif
    <div>
        <label for="notes" class="block text-sm font-medium text-gray-700">Catatan <span class="font-normal text-gray-500">(opsional)</span></label>
        <textarea id="notes" name="notes" rows="4" maxlength="65535" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $account->notes ?? '') }}</textarea>
        @error('notes')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    @if (!empty($hasPaymentHistory))
        <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">Identitas rekening tidak dapat diubah karena sudah digunakan dalam riwayat pembayaran. Nonaktifkan rekening ini dan buat rekening baru jika detail perlu diganti.</div>
    @endif
</div>
