@props(['order', 'storeOrder'])

@php
    $logs = $storeOrder->statusLogs->sortBy('created_at');
    $steps = [
        ['label' => 'Pesanan dibuat', 'time' => $order->created_at, 'done' => true, 'actor' => null],
        ['label' => 'Pembayaran dikonfirmasi', 'time' => $order->payment_confirmed_at, 'done' => $order->payment_status === 'paid', 'actor' => null],
    ];
    foreach (['awaiting_processing' => 'Menunggu diproses', 'processing' => 'Sedang diproses', 'ready_for_pickup' => 'Siap diambil', 'completed' => 'Selesai'] as $status => $label) {
        $log = $logs->firstWhere('to_status', $status);
        $steps[] = ['label' => $label, 'time' => $log?->created_at, 'done' => (bool) $log, 'actor' => $log?->actor?->name];
    }
    $cancelled = $order->order_status === 'cancelled' || $storeOrder->fulfillment_status === 'cancelled';
    $completedCount = collect($steps)->filter(fn ($step) => $step['done'])->count();
@endphp

<section class="order-timeline min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Perjalanan pesanan {{ $storeOrder->store_order_number }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h3 class="text-base font-bold text-slate-900">Perjalanan pesanan · {{ $storeOrder->store_name_snapshot }}</h3>
            <p class="mt-1 text-xs text-slate-500">{{ $storeOrder->store_order_number }} · {{ $storeOrder->fulfillmentStatusLabel() }}</p>
        </div>
        @if ($cancelled)<span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">Dibatalkan</span>@endif
    </div>
    <ol class="mt-6 ml-2 border-l-2 border-slate-200 pl-6">
        @foreach ($steps as $index => $step)
            @php $current = ! $cancelled && ! $step['done'] && $index === $completedCount; @endphp
            <li class="relative pb-6 last:pb-0">
                <span class="absolute -left-[33px] top-0 flex h-4 w-4 items-center justify-center rounded-full border-2 {{ $step['done'] ? 'border-emerald-600 bg-emerald-600' : ($current ? 'border-emerald-600 bg-white' : 'border-slate-300 bg-white') }}" aria-hidden="true"></span>
                <p class="text-sm font-semibold {{ $step['done'] || $current ? 'text-slate-900' : 'text-slate-400' }}">{{ $step['label'] }}@if($current) <span class="text-xs font-medium text-emerald-700">· Tahap berikutnya</span>@endif</p>
                @if ($step['time'])<p class="mt-1 text-xs text-slate-500">{{ $step['time']->format('d-m-Y H:i') }}@if($step['actor']) · {{ $step['actor'] }}@endif</p>@endif
            </li>
        @endforeach
        @if ($cancelled)
            <li class="relative">
                <span class="absolute -left-[33px] top-0 h-4 w-4 rounded-full border-2 border-red-600 bg-red-600" aria-hidden="true"></span>
                <p class="text-sm font-semibold text-red-700">Pesanan dibatalkan</p>
                @if ($order->cancelled_at)<p class="mt-1 text-xs text-slate-500">{{ $order->cancelled_at->format('d-m-Y H:i') }}</p>@endif
            </li>
        @endif
    </ol>
    @if ($order->payment_status === 'pending_confirmation')
        <p class="mt-5 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">Pembayaran diajukan dan masih menunggu verifikasi Admin BUMDes.</p>
    @endif
    @if ($order->payment_status === 'paid' && ! $order->payment_confirmed_at)
        <p class="mt-5 text-xs text-slate-500">Waktu konfirmasi pembayaran tidak tersedia pada data ini.</p>
    @endif
</section>
