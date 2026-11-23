@php
    $color = ['pending' => 'amber', 'verified' => 'green', 'rejected' => 'red'][$payment->status->value];
    $tk = fn ($amount) => 'Tk '.number_format((float) $amount, (float) $amount == (int) $amount ? 0 : 2);
    $pending = $payment->status->value === 'pending';
    $method = $payment->provider ?: ['mfs' => 'Mobile banking', 'bank' => 'Bank transfer', 'cash' => 'Cash'][$payment->method->value] ?? strtoupper($payment->method->value);
    $order = $payment->order;
@endphp
<x-admin.layout title="Payment #{{ $payment->id }}">
    {{-- header + actions --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('admin.payments.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                <x-admin.icon name="arrow-left" class="size-4" /> Back to payments
            </a>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-slate-900">Payment #{{ $payment->id }}</h1>
                <x-admin.badge :color="$color">{{ ucfirst($payment->status->value) }}</x-admin.badge>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                Submitted {{ $payment->created_at->format('d M Y, h:i A') }} by {{ $payment->student?->name ?? 'a student' }}
                for order <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $order->order_no }}</a>
            </p>
        </div>

        @if ($pending)
            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.payments.verify', $payment) }}" onsubmit="return confirm('Verify this payment? The dues it covers will be marked paid.')">
                    @csrf
                    @method('PATCH')
                    <x-admin.button type="submit"><x-admin.icon name="check" class="size-4" /> Verify</x-admin.button>
                </form>
                <x-admin.button type="button" variant="danger" onclick="document.getElementById('reject-form').classList.toggle('hidden')">
                    <x-admin.icon name="x" class="size-4" /> Reject
                </x-admin.button>
            </div>
        @endif
    </div>

    @if ($pending)
        <form id="reject-form" method="POST" action="{{ route('admin.payments.reject', $payment) }}" class="mb-6 hidden">
            @csrf
            @method('PATCH')
            <x-admin.card title="Reject this payment">
                <label class="mb-1 block text-sm font-medium text-slate-700">Reason (the student sees this)</label>
                <textarea name="reason" rows="2" required maxlength="255"
                          class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-red-600"
                          placeholder="e.g. TrxID doesn't match our statement"></textarea>
                <div class="mt-3">
                    <x-admin.button type="submit" variant="danger">Confirm reject</x-admin.button>
                </div>
            </x-admin.card>
        </form>
    @elseif ($payment->status->value === 'rejected' && $payment->reject_reason)
        <div class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            <span class="font-medium">Rejected:</span> {{ $payment->reject_reason }}
        </div>
    @endif

    {{-- what to match against the statement --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-admin.stat-card label="Amount" :value="$tk($payment->amount)" icon="credit-card" />
        <x-admin.stat-card label="TrxID" :value="$payment->trx_id ?? '—'" icon="file" :hint="$payment->method->value === 'cash' ? 'Cash payment' : null" />
        <x-admin.stat-card label="Paid with" :value="$method" icon="wallet" :hint="$payment->sender_account ? 'From '.$payment->sender_account : null" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- main: the transaction, then what it pays for --}}
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="Transaction">
                {{-- amount and TrxID are in the cards above --}}
                <div class="grid gap-x-10 md:grid-cols-2">
                    <x-admin.info-list :items="[
                        'Method' => $method,
                        'Sent from' => $payment->sender_account,
                        'To our account' => $payment->account ? trim(($payment->account->provider ?? '').' '.($payment->account->account_no ?? '')) : null,
                    ]" />
                    <x-admin.info-list :items="[
                        'Paid at' => $payment->paid_at?->format('d M Y, h:i A'),
                        'Submitted' => $payment->created_at->format('d M Y, h:i A'),
                        ($payment->status->value === 'rejected' ? 'Rejected by' : 'Verified by') => $payment->verifier ? $payment->verifier->name.($payment->verified_at ? ' · '.$payment->verified_at->format('d M Y, h:i A') : '') : null,
                    ]" />
                </div>
                @if ($payment->note)
                    <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600"><span class="font-medium text-slate-700">Student's note:</span> {{ $payment->note }}</p>
                @endif
            </x-admin.card>

            <x-admin.card title="What this payment covers" :padded="false">
                <x-admin.table>
                    <x-slot:head>
                        <th>Course</th>
                        <th>Batch</th>
                        <th>Due</th>
                        <th class="text-right">Amount</th>
                    </x-slot:head>
                    @foreach ($payment->installments as $inst)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $inst->item?->course?->title ?? '—' }}
                                @if ($inst->item?->renews_enrollment_id) <x-admin.badge color="amber">Renewal</x-admin.badge> @endif
                            </td>
                            <td class="text-slate-500">{{ $inst->item?->batch?->name ?? '—' }}</td>
                            <td class="text-slate-500">{{ $inst->title }}</td>
                            <td class="text-right font-medium text-slate-900">{{ $tk($inst->amount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="bg-slate-50">
                        <td colspan="3" class="font-semibold text-slate-900">Total</td>
                        <td class="text-right font-semibold text-slate-900">{{ $tk($payment->amount) }}</td>
                    </tr>
                </x-admin.table>
            </x-admin.card>
        </div>

        {{-- side: who, and the order it belongs to --}}
        <div class="space-y-6">
            <x-admin.student-card :student="$payment->student" />

            <x-admin.card title="Order">
                <x-slot:actions>
                    <x-admin.button :href="route('admin.orders.show', $order)" variant="secondary" size="sm">Open</x-admin.button>
                </x-slot:actions>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="font-medium text-slate-900">{{ $order->order_no }}</span>
                    <x-admin.order-status :status="$order->status" />
                </div>
                <x-admin.info-list :items="[
                    'Order total' => $tk($order->total),
                    'Paid so far' => $tk($order->paid_amount),
                    'Still due' => $tk(in_array($order->status->value, ['expired', 'cancelled']) ? 0 : $order->dueAmount()),
                ]" />
            </x-admin.card>
        </div>
    </div>
</x-admin.layout>
