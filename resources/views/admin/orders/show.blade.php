@php
    $dueColors = ['unpaid' => 'amber', 'pending' => 'blue', 'paid' => 'green', 'cancelled' => 'gray'];
    $dueLabels = ['unpaid' => 'Not paid', 'pending' => 'Being verified', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
    $payColors = ['pending' => 'amber', 'verified' => 'green', 'rejected' => 'red'];
    $tk = fn ($amount) => 'Tk '.number_format((float) $amount, (float) $amount == (int) $amount ? 0 : 2);
@endphp
<x-admin.layout title="Order {{ $order->order_no }}">
    <div class="mb-6">
        <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
            <x-admin.icon name="arrow-left" class="size-4" /> Back to orders
        </a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-slate-900">Order {{ $order->order_no }}</h1>
            <x-admin.order-status :status="$order->status" />
            @if ($order->combo)
                <x-admin.badge color="indigo">Combo: {{ $order->combo->title }}</x-admin.badge>
            @endif
        </div>
        <p class="mt-1 text-sm text-slate-500">Placed {{ $order->created_at->format('d M Y, h:i A') }}</p>
    </div>

    @if ($order->status->value === 'pending' && $order->expires_at)
        <div class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20">
            Waiting for payment until <strong>{{ $order->expires_at->format('d M Y, h:i A') }}</strong>. After that it expires and its seats and voucher are released
            (unless a payment is waiting for review).
        </div>
    @endif

    {{-- money at a glance --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-admin.stat-card label="Total" :value="$tk($order->total)" icon="credit-card" :hint="$order->items->count().' '.str('course')->plural($order->items->count())" />
        <x-admin.stat-card label="Paid" :value="$tk($order->paid_amount)" icon="check-circle" :hint="$order->completed_at ? 'Fully paid '.$order->completed_at->format('d M Y') : null" />
        <x-admin.stat-card label="Still due" :value="$tk(in_array($order->status->value, ['expired', 'cancelled']) ? 0 : $order->dueAmount())" icon="clock"
            :hint="in_array($order->status->value, ['expired', 'cancelled']) ? 'Order '.$order->status->value : null" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- main: courses + their dues, then payments --}}
        <div class="space-y-6 lg:col-span-2">
            @foreach ($order->items as $item)
                <x-admin.card :title="$item->course?->title ?? 'Course removed'">
                    <x-slot:actions>
                        <x-admin.badge :color="$item->payment_plan->value === 'installment' ? 'blue' : 'gray'">
                            {{ $item->payment_plan->value === 'installment' ? 'Installments' : 'Full payment' }}
                        </x-admin.badge>
                        @if ($item->enrollment)
                            <x-admin.badge color="green">Enrolled</x-admin.badge>
                        @endif
                    </x-slot:actions>

                    <div class="mb-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        <p class="text-slate-500">Batch: <span class="font-medium text-slate-900">{{ $item->batch?->name ?? '—' }}</span>
                            @if ($item->batch?->start_date) <span class="text-slate-400">· starts {{ $item->batch->start_date->format('d M Y') }}</span> @endif
                        </p>
                        <p class="text-slate-500 sm:text-right">
                            @if ((float) $item->list_price > (float) $item->sale_price)
                                <span class="text-slate-400 line-through">{{ $tk($item->list_price) }}</span>
                            @endif
                            <span class="font-medium text-slate-900">{{ $tk($item->sale_price) }}</span>
                            @if ((float) $item->combo_discount > 0) <span class="text-emerald-700">− {{ $tk($item->combo_discount) }} combo</span> @endif
                            @if ((float) $item->voucher_discount > 0) <span class="text-emerald-700">− {{ $tk($item->voucher_discount) }} voucher</span> @endif
                            = <span class="font-semibold text-slate-900">{{ $tk($item->net_price) }}</span>
                        </p>
                    </div>

                    <div class="overflow-hidden rounded-lg ring-1 ring-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">Due</th>
                                    <th class="px-4 py-2 text-right font-semibold">Amount</th>
                                    <th class="px-4 py-2 font-semibold">Status</th>
                                    <th class="px-4 py-2 font-semibold">Paid on</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($item->installments as $due)
                                    <tr>
                                        <td class="px-4 py-2 text-slate-900">{{ $due->title }}</td>
                                        <td class="px-4 py-2 text-right font-medium text-slate-900">{{ $tk($due->amount) }}</td>
                                        <td class="px-4 py-2"><x-admin.badge :color="$dueColors[$due->status->value] ?? 'gray'">{{ $dueLabels[$due->status->value] ?? $due->status->value }}</x-admin.badge></td>
                                        <td class="px-4 py-2 text-slate-500">{{ $due->paid_at?->format('d M Y') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>
            @endforeach

            <x-admin.card title="Payments sent for this order" :padded="false">
                @if ($order->payments->isEmpty())
                    <x-admin.empty-state icon="inbox" title="No payment yet" message="Payments the student submits for this order show up here." />
                @else
                    <x-admin.table>
                        <x-slot:head>
                            <th>Submitted</th>
                            <th>Method</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </x-slot:head>
                        @foreach ($order->payments as $payment)
                            <tr>
                                <td class="whitespace-nowrap text-slate-500">{{ $payment->created_at->format('d M Y, h:i A') }}</td>
                                <td>{{ $payment->provider ?: strtoupper($payment->method->value) }}</td>
                                <td class="whitespace-nowrap text-right font-medium text-slate-900">{{ $tk($payment->amount) }}</td>
                                <td><x-admin.badge :color="$payColors[$payment->status->value]">{{ ucfirst($payment->status->value) }}</x-admin.badge></td>
                                <td class="text-right">
                                    <x-admin.button :href="route('admin.payments.show', $payment)" variant="secondary" size="sm">
                                        {{ $payment->status->value === 'pending' ? 'Review' : 'View' }}
                                    </x-admin.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.card>
        </div>

        {{-- side: who and how much --}}
        <div class="space-y-6">
            <x-admin.student-card :student="$order->student" />

            <x-admin.card title="Amounts">
                <x-admin.info-list :items="array_filter([
                    'Subtotal' => $tk($order->subtotal),
                    'Combo discount' => (float) $order->combo_discount > 0 ? '− '.$tk($order->combo_discount) : null,
                    'Voucher discount' => (float) $order->voucher_discount > 0 ? '− '.$tk($order->voucher_discount) : null,
                    'Total' => $tk($order->total),
                    'Paid' => $tk($order->paid_amount),
                ], fn ($v) => $v !== null)" />
            </x-admin.card>

            <x-admin.card title="Details">
                <x-admin.info-list :items="array_filter([
                    'Combo' => $order->combo?->title,
                    'Voucher' => $order->voucher ? $order->voucher->code.($order->voucher->title ? ' ('.$order->voucher->title.')' : '') : null,
                    'Referred by' => $order->attribution?->affiliate?->name,
                    'Pay before' => $order->status->value === 'pending' ? $order->expires_at?->format('d M Y, h:i A') : null,
                    'Fully paid on' => $order->completed_at?->format('d M Y, h:i A'),
                    'Cancelled on' => $order->cancelled_at?->format('d M Y, h:i A'),
                    'Placed' => $order->created_at->format('d M Y, h:i A'),
                ], fn ($v) => $v !== null)" />
            </x-admin.card>
        </div>
    </div>
</x-admin.layout>
