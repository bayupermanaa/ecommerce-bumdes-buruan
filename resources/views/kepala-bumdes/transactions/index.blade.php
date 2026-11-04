<x-kepala-bumdes-layout title="Laporan Transaksi">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Laporan Transaksi</h2>
            <a href="{{ route('kepala-bumdes.dashboard') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Kembali ke Dashboard</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form method="GET" action="{{ route('kepala-bumdes.transactions.index') }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <x-input-label for="date_from" value="Tanggal dari" />
                        <x-text-input id="date_from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" />
                    </div>
                    <div>
                        <x-input-label for="date_to" value="Tanggal sampai" />
                        <x-text-input id="date_to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" />
                    </div>
                    <div>
                        <x-input-label for="payment_status" value="Status pembayaran" />
                        <select id="payment_status" name="payment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            <option value="unpaid" @selected($filters['payment_status'] === 'unpaid')>Belum Dibayar</option>
                            <option value="pending_confirmation" @selected($filters['payment_status'] === 'pending_confirmation')>Menunggu Verifikasi</option>
                            <option value="paid" @selected($filters['payment_status'] === 'paid')>Sudah Dibayar</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="order_status" value="Status fulfillment" />
                        <select id="order_status" name="order_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            <option value="pending_payment" @selected($filters['order_status'] === 'pending_payment')>Menunggu Pembayaran</option>
                            <option value="in_fulfillment" @selected($filters['order_status'] === 'in_fulfillment')>Dalam Proses</option>
                            <option value="completed" @selected($filters['order_status'] === 'completed')>Selesai</option>
                            <option value="cancelled" @selected($filters['order_status'] === 'cancelled')>Dibatalkan</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <x-primary-button>Filter</x-primary-button>
                        <a href="{{ route('kepala-bumdes.transactions.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Reset</a>
                    </div>
                </form>
                @if ($errors->any())
                    <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif
            </div>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Nomor / Tanggal</th>
                                <th class="px-6 py-3">Pembayaran</th>
                                <th class="px-6 py-3">Fulfillment</th>
                                <th class="px-6 py-3 text-right">Total Pesanan</th>
                                <th class="px-6 py-3 text-right">Komisi Snapshot</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-700">
                            @forelse ($orders as $order)
                                <tr>
                                    <td class="px-6 py-4"><p class="font-medium text-gray-900">{{ $order->order_number }}</p><p class="text-xs text-gray-500">{{ $order->created_at->translatedFormat('d M Y H:i') }} · {{ $order->store_orders_count }} toko</p></td>
                                    <td class="px-6 py-4">{{ $order->paymentStatusLabel() }}</td>
                                    <td class="px-6 py-4">{{ $order->orderStatusLabel() }}</td>
                                    <td class="px-6 py-4 text-right">Rp {{ number_format((float) $order->grand_total, 2, ',', '.') }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @if ($order->payment_status === 'paid')
                                            Rp {{ number_format((float) ($order->commission_snapshot_total ?? 0), 2, ',', '.') }}
                                        @else
                                            <span class="text-gray-500">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Tidak ada transaksi sesuai filter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($orders->hasPages())
                    <div class="border-t border-gray-200 px-6 py-4">{{ $orders->links() }}</div>
                @endif
            </div>
            <p class="text-xs text-gray-500">Setiap baris adalah satu pesanan induk. Komisi dijumlahkan dari snapshot seluruh toko pada pesanan tersebut hanya ketika pembayaran sudah terkonfirmasi.</p>
        </div>
    </div>
</x-kepala-bumdes-layout>
