<div class="flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.sellers.show', ['store' => $store] + $returnParams) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700"><x-admin-icon name="eye" />Detail Toko</a>
    @if ($store->status === 'pending')
        <form method="POST" action="{{ route('admin.umkm-stores.approve', $store) }}" onsubmit="return confirm('Setujui pengajuan toko ini?')">
            @csrf @method('PATCH')
            @foreach ($returnParams as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700">Setujui</button>
        </form>
        <form method="POST" action="{{ route('admin.umkm-stores.reject', $store) }}" class="flex flex-wrap items-center gap-2">
            @csrf @method('PATCH')
            @foreach ($returnParams as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <label class="sr-only" for="reason-{{ $store->id }}-{{ $surface ?? 'table' }}">Alasan penolakan {{ $store->name }}</label>
            <input id="reason-{{ $store->id }}-{{ $surface ?? 'table' }}" name="rejection_reason" type="text" required maxlength="1000" placeholder="Alasan penolakan" class="w-40 rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <button type="submit" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">Tolak</button>
        </form>
    @elseif ($store->verified_at)
        <span class="text-xs text-slate-500">Diverifikasi {{ $store->verified_at->format('d M Y H:i') }}</span>
        @if ($store->status === 'rejected' && $store->rejection_reason)
            <span class="w-full text-xs text-rose-700">Alasan: {{ $store->rejection_reason }}</span>
        @endif
    @else
        <span class="text-xs text-slate-500">Waktu verifikasi belum tercatat</span>
    @endif
</div>
