@php
    $color = ['pending' => 'amber', 'verified' => 'green', 'rejected' => 'red'][$payment->status->value];
@endphp
<x-admin.layout title="Payment #{{ $payment->id }}">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="{{ route('admin.payments.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                <x-admin.icon name="arrow-left" class="size-4" /> Back to payments
            </a>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">
                Payment #{{ $payment->id }}
                <x-admin.badge :color="$color">{{ ucfirst($payment->status->value) }}</x-admin.badge>
            </h1>
        </div>

        @if ($payment->status->value === 'pending')
            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.payments.verify', $payment) }}" onsubmit="return confirm('Verify this payment? The dues it covers will be marked paid.')">
                    @csrf
                    @method('PATCH')
                    <x-admin.button type="submit">Verify</x-admin.button>
                </form>
                <x-admin.button type="button" variant="danger" onclick="document.getElementById('reject-form').classList.toggle('hidden')">Reject</x-admin.button>
            </div>
        @endif
    </div>

    @if ($payment->status->value === 'pending')
        <form id="reject-form" method="POST" action="{{ route('admin.payments.reject', $payment) }}" class="mb-6 hidden">
            @csrf
            @method('PATCH')
            <x-admin.card title="Reject this payment">
                <label class="mb-1 block text-sm font-medium text-slate-700">Reason</label>
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

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="What this payment covers">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="pb-2">Course</th>
                            <th class="pb-2">Due</th>
                            <th class="pb-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($payment->installments as $inst)
                            <tr>
                                <td class="py-2 text-slate-900">{{ $inst->item?->course?->title ?? '—' }}</td>
                                <td class="py-2 text-slate-500">{{ $inst->title }}</td>
                                <td class="py-2 text-right font-medium text-slate-900">Tk {{ number_format((float) $inst->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200">
                            <td colspan="2" class="pt-2 font-medium text-slate-900">Total</td>
                            <td class="pt-2 text-right font-semibold text-slate-900">Tk {{ number_format((float) $payment->amount) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </x-admin.card>

            @if ($payment->proof_image_url)
                <x-admin.card title="Proof submitted">
                    <a href="{{ $payment->proof_image_url }}" target="_blank" rel="noopener">
                        <img src="{{ $payment->proof_image_url }}" alt="Payment proof" class="max-h-96 rounded-lg ring-1 ring-slate-200">
                    </a>
                </x-admin.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-admin.card title="Student">
                <p class="font-medium text-slate-900">{{ $payment->student->name }}</p>
                <p class="text-sm text-slate-500">{{ $payment->student->email }}</p>
            </x-admin.card>

            <x-admin.card title="Order">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Order no</dt><dd class="font-medium text-slate-900">{{ $payment->order->order_no }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Order total</dt><dd>Tk {{ number_format((float) $payment->order->total) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Paid so far</dt><dd>Tk {{ number_format((float) $payment->order->paid_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Still due</dt><dd>Tk {{ number_format($payment->order->dueAmount()) }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card title="Submitted via">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Method</dt><dd class="uppercase">{{ $payment->method->value }}</dd></div>
                    @if ($payment->provider)
                        <div class="flex justify-between"><dt class="text-slate-500">Provider</dt><dd>{{ $payment->provider }}</dd></div>
                    @endif
                    @if ($payment->sender_account)
                        <div class="flex justify-between"><dt class="text-slate-500">From</dt><dd class="font-mono">{{ $payment->sender_account }}</dd></div>
                    @endif
                    @if ($payment->trx_id)
                        <div class="flex justify-between"><dt class="text-slate-500">TrxID</dt><dd class="font-mono">{{ $payment->trx_id }}</dd></div>
                    @endif
                    @if ($payment->account)
                        <div class="flex justify-between"><dt class="text-slate-500">To our account</dt><dd>{{ $payment->account->account_no ?? $payment->account->provider }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Paid at</dt><dd>{{ $payment->paid_at?->format('d M Y, h:i A') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Submitted</dt><dd>{{ $payment->created_at->format('d M Y, h:i A') }}</dd></div>
                    @if ($payment->verifier)
                        <div class="flex justify-between"><dt class="text-slate-500">Handled by</dt><dd>{{ $payment->verifier->name }}</dd></div>
                    @endif
                    @if ($payment->note)
                        <div class="pt-1 text-slate-500">"{{ $payment->note }}"</div>
                    @endif
                </dl>
            </x-admin.card>
        </div>
    </div>
</x-admin.layout>
