<x-admin.layout title="Payments">
    <x-admin.page-header title="Payments" description="Proof of payment submitted by students, waiting for (or already given) verification." />

    <form method="GET" action="{{ route('admin.payments.index') }}" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_auto]">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by order no, student or TrxID..."
               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600">

        <select name="status" class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-600">
            <option value="pending" @selected($filter === 'pending')>Pending</option>
            <option value="verified" @selected($filter === 'verified')>Verified</option>
            <option value="rejected" @selected($filter === 'rejected')>Rejected</option>
            <option value="" @selected($filter === '')>Any status</option>
        </select>

        <div class="flex gap-2">
            <x-admin.button type="submit">Filter</x-admin.button>
            @if (request()->hasAny(['q']) || $filter !== 'pending')
                <x-admin.button :href="route('admin.payments.index')" variant="secondary">Reset</x-admin.button>
            @endif
        </div>
    </form>

    <x-admin.card :padded="false">
        @if ($payments->isEmpty())
            <x-admin.empty-state icon="inbox"
                :title="$filter === 'pending' ? 'Nothing waiting for review' : 'No payments match'"
                message="Submissions from students show up here." />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th>Student</th>
                    <th>Order</th>
                    <th>For</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-right">Actions</th>
                </x-slot:head>
                @foreach ($payments as $payment)
                    @php
                        $color = ['pending' => 'amber', 'verified' => 'green', 'rejected' => 'red'][$payment->status->value];
                    @endphp
                    <tr>
                        <td>
                            <p class="font-medium text-slate-900">{{ $payment->student->name }}</p>
                            <p class="text-xs text-slate-500">{{ $payment->student->email }}</p>
                        </td>
                        <td class="text-slate-500">{{ $payment->order->order_no }}</td>
                        <td class="text-xs text-slate-500">
                            {{ $payment->installments->pluck('item.course.title')->unique()->filter()->join(', ') }}
                        </td>
                        <td class="uppercase text-slate-500">{{ $payment->method->value }}</td>
                        <td class="font-medium text-slate-900">Tk {{ number_format((float) $payment->amount) }}</td>
                        <td><x-admin.badge :color="$color">{{ ucfirst($payment->status->value) }}</x-admin.badge></td>
                        <td class="text-slate-500">{{ $payment->created_at->format('d M Y, h:i A') }}</td>
                        <td class="whitespace-nowrap text-right">
                            <x-admin.button :href="route('admin.payments.show', $payment)" variant="secondary">View</x-admin.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.card>

    @if ($payments->hasPages())
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</x-admin.layout>
