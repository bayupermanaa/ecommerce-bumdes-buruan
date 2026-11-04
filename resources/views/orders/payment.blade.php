<x-catalog-layout :title="'Pembayaran '.$order->order_number">
    <section class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('orders.show', $order) }}" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:border-emerald-200 hover:text-emerald-700">&larr; Detail Pesanan</a>

        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Pengajuan Pembayaran</p>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $order->order_number }}</h1>
            <p class="mt-2 text-sm text-gray-600">Total tagihan: <span class="font-semibold text-gray-900">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</span></p>

            @if (! $order->canSubmitPayment())
                <div class="mt-5 rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    Pengajuan baru tidak tersedia karena status pembayaran pesanan ini adalah {{ strtolower($order->paymentStatusLabel()) }}.
                </div>
            @endif

            <form method="POST" action="{{ route('orders.payments.store', $order) }}" enctype="multipart/form-data" class="mt-6 space-y-5" data-payment-form>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                <fieldset {{ $order->canSubmitPayment() ? '' : 'disabled' }}>
                    <legend class="text-sm font-semibold text-gray-900">Metode pembayaran</legend>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-green-700 bg-green-50 p-4" data-method-card="bank_transfer">
                            <input type="radio" name="payment_method" value="bank_transfer" class="mt-1 border-gray-300 text-green-700 focus:ring-green-700" {{ old('payment_method', 'bank_transfer') === 'bank_transfer' ? 'checked' : '' }}>
                            <span><span class="block font-semibold text-gray-900">Transfer Bank</span><span class="mt-1 block text-sm text-gray-600">Unggah bukti transfer ke rekening resmi BUMDes.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-300 bg-white p-4" data-method-card="cash_pickup">
                            <input type="radio" name="payment_method" value="cash_pickup" class="mt-1 border-gray-300 text-green-700 focus:ring-green-700" {{ old('payment_method') === 'cash_pickup' ? 'checked' : '' }}>
                            <span><span class="block font-semibold text-gray-900">Tunai Saat Pickup</span><span class="mt-1 block text-sm text-gray-600">Bayarkan tunai kepada petugas BUMDes saat pengambilan.</span></span>
                        </label>
                    </div>
                    @error('payment_method')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

                    <div class="mt-5 space-y-4" data-method-section="bank_transfer">
                        @if ($bankAccounts->isEmpty())
                            <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">Belum ada rekening BUMDes aktif. Pengajuan transfer belum dapat dilakukan.</div>
                        @else
                            <div>
                                <label for="bumdes_bank_account_id" class="block text-sm font-medium text-gray-700">Rekening resmi BUMDes</label>
                                <select id="bumdes_bank_account_id" name="bumdes_bank_account_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-green-700 focus:ring-green-700">
                                    <option value="">Pilih rekening</option>
                                    @foreach ($bankAccounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old('bumdes_bank_account_id') === (string) $account->id)>{{ $account->bank_name }} - {{ $account->account_number }} a.n. {{ $account->account_holder_name }}</option>
                                    @endforeach
                                </select>
                                @error('bumdes_bank_account_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><label for="payer_name" class="block text-sm font-medium text-gray-700">Nama pengirim</label><input id="payer_name" name="payer_name" value="{{ old('payer_name') }}" maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-green-700 focus:ring-green-700">@error('payer_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                                <div><label for="transfer_reference" class="block text-sm font-medium text-gray-700">Referensi transfer</label><input id="transfer_reference" name="transfer_reference" value="{{ old('transfer_reference') }}" maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-green-700 focus:ring-green-700">@error('transfer_reference')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                            </div>
                            <div><label for="transfer_proof" class="block text-sm font-medium text-gray-700">Bukti transfer</label><input id="transfer_proof" type="file" name="transfer_proof" accept="image/jpeg,image/png,application/pdf" class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-green-800 hover:file:bg-green-100"> <p class="mt-1 text-xs text-gray-500">JPG, PNG, atau PDF, maksimal 2 MB.</p>@error('transfer_proof')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                        @endif
                    </div>

                    <div class="mt-5 hidden rounded-md bg-green-50 p-4 text-sm text-green-900" data-method-section="cash_pickup">
                        Pembayaran tunai belum diterima saat ini. Bayarkan total tagihan kepada petugas BUMDes ketika mengambil pesanan; Admin BUMDes akan memverifikasi penerimaannya.
                    </div>
                </fieldset>

                @if ($order->canSubmitPayment())
                    <button type="submit" class="inline-flex w-full justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400" data-submit-payment {{ $bankAccounts->isEmpty() ? 'disabled' : '' }}>Ajukan Pembayaran</button>
                @endif
            </form>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-payment-form]');
            if (!form) return;

            const syncMethod = () => {
                const method = form.querySelector('input[name="payment_method"]:checked')?.value;
                form.querySelectorAll('[data-method-section]').forEach((section) => {
                    const active = section.dataset.methodSection === method;
                    section.classList.toggle('hidden', !active);
                    section.querySelectorAll('input, select').forEach((input) => input.disabled = !active);
                });
                form.querySelectorAll('[data-method-card]').forEach((card) => {
                    const active = card.dataset.methodCard === method;
                    card.classList.toggle('border-green-700', active);
                    card.classList.toggle('bg-green-50', active);
                    card.classList.toggle('border-gray-300', !active);
                    card.classList.toggle('bg-white', !active);
                });
                const submit = form.querySelector('[data-submit-payment]');
                if (submit) submit.disabled = method === 'bank_transfer' && {{ $bankAccounts->isEmpty() ? 'true' : 'false' }};
            };

            form.querySelectorAll('input[name="payment_method"]').forEach((input) => input.addEventListener('change', syncMethod));
            form.addEventListener('submit', () => {
                const submit = form.querySelector('[data-submit-payment]');
                if (submit) {
                    submit.disabled = true;
                    submit.textContent = 'Mengajukan...';
                }
            });
            syncMethod();
        });
    </script>
</x-catalog-layout>
