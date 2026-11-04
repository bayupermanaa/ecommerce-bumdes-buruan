<x-umkm-layout title="Pencairan Dana">
<div class="mx-auto max-w-6xl space-y-6">
@if(session('status'))<div class="bg-green-50 p-3 text-green-700">{{session('status')}}</div>@endif
@if($errors->any())<div class="bg-red-50 p-3 text-red-700">{{$errors->first()}}</div>@endif
<div class="grid gap-4 md:grid-cols-3"><div class="rounded bg-white p-4 shadow">Saldo tersedia<strong class="block">Rp{{$balances['available']}}</strong></div><div class="rounded bg-white p-4 shadow">Saldo direservasi<strong class="block">Rp{{$balances['reserved']}}</strong></div><div class="rounded bg-white p-4 shadow">Hak ditahan<strong class="block">{{$balances['held_count']}}</strong></div></div>
<div class="rounded bg-white p-5 shadow"><form method="POST" action="{{route('umkm.disbursements.store')}}">@csrf<input type="hidden" name="idempotency_key" value="{{old('idempotency_key',(string) \Illuminate\Support\Str::uuid())}}"><x-primary-button :disabled="$balances['available']==='0.00'||!$account||!$account->verified_at||$activeDisbursement">Ajukan Seluruh Saldo Tersedia</x-primary-button></form></div>
<div class="overflow-x-auto rounded bg-white shadow"><table class="min-w-full text-sm"><thead><tr><th class="p-3 text-left">Tanggal</th><th>Nominal</th><th>Status</th><th class="p-3">Aksi</th></tr></thead><tbody>
@forelse($disbursements as $disbursement)
<tr class="border-t"><td class="p-3">{{$disbursement->requested_at->translatedFormat('d M Y H:i')}}</td><td>Rp{{$disbursement->requested_amount}}</td><td>{{['requested'=>'Menunggu Pemeriksaan','under_review'=>'Sedang Diperiksa','approved'=>'Disetujui','paid'=>'Selesai Dibayarkan','rejected'=>'Ditolak','cancelled'=>'Dibatalkan','transfer_failed'=>'Transfer Gagal'][$disbursement->status]??$disbursement->status}} @if($disbursement->rejection_reason)<p class="text-xs text-red-600">{{$disbursement->rejection_reason}}</p>@endif @if($disbursement->transfer_failure_reason)<p class="text-xs text-red-600">{{$disbursement->transfer_failure_reason}}</p>@endif</td><td class="p-3">
@can('cancelRequested',$disbursement)
@if($disbursement->status === 'requested')
<form method="POST" action="{{route('umkm.disbursements.cancel',$disbursement)}}" onsubmit="return confirm('Batalkan pengajuan ini?')">@csrf @method('PATCH')<x-text-input name="reason" placeholder="Alasan pembatalan" required/><button class="mt-1 text-red-600">Batalkan Pengajuan</button></form>
@endif
@endcan
</td></tr>
@empty<tr><td colspan="4" class="p-5 text-center">Belum ada pengajuan.</td></tr>
@endforelse
</tbody></table><div class="p-3">{{$disbursements->links()}}</div></div>
</div></div>
</x-umkm-layout>
