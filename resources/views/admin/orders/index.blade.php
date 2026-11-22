@php
    $tiles = [
        ['status' => 'pending', 'label' => 'Waiting for payment', 'icon' => 'clock'],
        ['status' => 'partially_paid', 'label' => 'Partly paid', 'icon' => 'activity'],
        ['status' => 'paid', 'label' => 'Paid', 'icon' => 'check-circle'],
        ['status' => 'expired', 'label' => 'Expired', 'icon' => 'x-circle'],
    ];
@endphp
<x-admin.layout title="Orders">
    <x-admin.page-header title="Orders" description="Every checkout: its courses, what's paid and what's still due." />

    {{-- counts per status; click one to filter --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as $tile)
            <a href="{{ route('admin.orders.index', ['status' => $status === $tile['status'] ? null : $tile['status']]) }}"
               @class(['rounded-xl transition hover:ring-2 hover:ring-indigo-200', 'ring-2 ring-indigo-500' => $status === $tile['status']])>
                <x-admin.stat-card :label="$tile['label']" :value="(int) ($counts[$tile['status']] ?? 0)" :icon="$tile['icon']" />
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.orders.index') }}" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_auto]">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by order no, student name, email or phone..."
               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600">

        <select name="status" class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-600">
            <option value="" @selected(! $status)>Any status</option>
            <option value="pending" @selected($status === 'pending')>Waiting for payment</option>
            <option value="partially_paid" @selected($status === 'partially_paid')>Partly paid</option>
            <option value="paid" @selected($status === 'paid')>Paid</option>
            <option value="expired" @selected($status === 'expired')>Expired</option>
            <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
        </select>

        <div class="flex gap-2">
            <x-admin.button type="submit">Filter</x-admin.button>
            @if (request()->filled('q') || $status)
                <x-admin.button :href="route('admin.orders.index')" variant="secondary">Reset</x-admin.button>
            @endif
        </div>
    </form>

    <x-admin.card :padded="false">
        @if ($orders->isEmpty())
            <x-admin.empty-state icon="credit-card" title="No orders match" message="Orders show up here as soon as a student checks out." />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th>Order</th>
                    <th>Student</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Paid</th>
                    <th>Status</th>
                    <th>Placed</th>
                    <th class="text-right">Actions</th>
                </x-slot:head>
                @foreach ($orders as $order)
                    <tr>
                        <td class="whitespace-nowrap">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $order->order_no }}</a>
                        </td>
                        <td>
                            <p class="font-medium text-slate-900">{{ $order->student?->name ?? '—' }}</p>
                            <p class="text-xs text-slate-500">{{ $order->student?->email }}</p>
                        </td>
                        <td class="whitespace-nowrap text-right font-medium text-slate-900">Tk {{ number_format((float) $order->total) }}</td>
                        <td class="whitespace-nowrap text-right text-slate-600">Tk {{ number_format((float) $order->paid_amount) }}</td>
                        <td>
                            <div class="flex flex-col items-start gap-1">
                                <x-admin.order-status :status="$order->status" />
                                @if ($order->payments_waiting)
                                    <a href="{{ route('admin.payments.index', ['q' => $order->order_no]) }}">
                                        <x-admin.badge color="amber">{{ $order->payments_waiting }} to verify</x-admin.badge>
                                    </a>
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-slate-500">{{ $order->created_at->format('d M Y, h:i A') }}</td>
                        <td class="whitespace-nowrap text-right">
                            <x-admin.button :href="route('admin.orders.show', $order)" variant="secondary" size="sm">View</x-admin.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.card>

    @if ($orders->hasPages())
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-admin.layout>
