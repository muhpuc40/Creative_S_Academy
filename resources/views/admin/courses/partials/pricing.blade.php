{{-- Current price + installments (active price only) and the price history. --}}
@php
    $taka = fn ($n) => '৳ '.number_format((float) $n, 0);
    $days = fn ($d) => $d.' days'.($d % 365 === 0 ? ' ('.($d / 365).' '.($d === 365 ? 'year' : 'years').')' : ($d % 30 === 0 ? ' ('.($d / 30).' '.($d === 30 ? 'month' : 'months').')' : ''));
    $final = $currentPrice?->finalPrice();
    $planTotal = $currentPrice ? $rules->where('is_active', true)->sum(fn ($r) => $r->amountFor($final)) : 0;
    $planOk = $currentPrice?->hasValidPaymentPlan();
@endphp

<div class="space-y-6"
     x-data="{
        dlg: null, mode: 'add', action: '', form: {},
        open(kind, mode, action, form) { this.dlg = kind; this.mode = mode; this.action = action; this.form = form; },
     }">

    {{-- ------------------------------------------------------------ current price | access & renewal --}}
    <x-admin.card>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="font-semibold text-slate-900">Price</h3>
                <p class="text-xs text-slate-500">Changing it saves a new price. Students who already enrolled keep what they bought.</p>
            </div>
            <x-admin.button type="button"
                @click="open('price', 'add', '{{ route('admin.prices.store', $course) }}', {
                    actual_price: {{ $currentPrice ? (float) $currentPrice->actual_price : 0 }},
                    discount_type: '{{ $currentPrice?->discount_type?->value ?? '' }}',
                    discount_value: {{ $currentPrice ? (float) $currentPrice->discount_value : 0 }},
                    access_days: {{ $currentPrice?->access_days ?? 180 }},
                    renewal_fee: '{{ $currentPrice?->renewal_fee !== null ? (float) $currentPrice->renewal_fee : '' }}',
                    renewal_days: '{{ $currentPrice?->renewal_days ?? '' }}',
                    copy_rules: true })">
                <x-admin.icon name="edit" class="size-4" /> {{ $currentPrice ? 'Change price' : 'Set price' }}
            </x-admin.button>
        </div>

        @if ($currentPrice)
            <div class="grid divide-y divide-slate-100 md:grid-cols-2 md:divide-x md:divide-y-0">
                {{-- left: what a new student pays --}}
                <div class="p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Current price</p>
                    <p class="mt-2 flex flex-wrap items-baseline gap-2">
                        <span class="text-3xl font-bold text-slate-900">{{ $taka($final) }}</span>
                        @if ($currentPrice->discount_type)
                            <span class="text-slate-400 line-through">{{ $taka($currentPrice->actual_price) }}</span>
                            <x-admin.badge color="green">
                                {{ $currentPrice->discount_type->value === 'percent' ? rtrim(rtrim($currentPrice->discount_value, '0'), '.').'% off' : $taka($currentPrice->discount_value).' off' }}
                            </x-admin.badge>
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Set {{ $currentPrice->created_at?->diffForHumans() }}.</p>
                </div>

                {{-- right: how long they can watch, and what renewing costs --}}
                <div class="p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Access &amp; renewal</p>
                    <dl class="mt-3 space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-500">Access period</dt>
                            <dd class="font-medium text-slate-900">{{ $days($currentPrice->access_days) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-500">Renewal fee</dt>
                            <dd class="font-medium text-slate-900">{{ $currentPrice->offersRenewal() ? $taka($currentPrice->renewal_fee) : 'Not offered' }}</dd>
                        </div>
                        @if ($currentPrice->offersRenewal())
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-slate-500">One renewal adds</dt>
                                <dd class="font-medium text-slate-900">{{ $days($currentPrice->renewal_days) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        @else
            <p class="p-5 text-sm text-slate-500">No price yet. The course cannot be sold until you add one.</p>
        @endif
    </x-admin.card>

    {{-- ------------------------------------------------------------ installments --}}
    @if ($currentPrice)
        <x-admin.card>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="font-semibold text-slate-900">Installments</h3>
                    <p class="text-xs text-slate-500">Empty = student pays the full price at enrollment. Percent is taken from the price after discount.</p>
                </div>
            <x-admin.button type="button" variant="secondary" size="sm"
                @click="open('rule', 'add', '{{ route('admin.rules.store', $currentPrice) }}', { rule_name: '', course_modules_id: '', amount_type: 'percent', amount: '', sort_order: '', is_active: true })">
                <x-admin.icon name="plus" class="size-4" /> Add installment
            </x-admin.button>
            </div>

            @if ($rules->isEmpty())
                <x-admin.empty-state icon="credit-card" title="Full payment only" message="Add installments if students may pay in parts." />
            @else
                <x-admin.table>
                    <x-slot:head><th>#</th><th>Name</th><th>Due</th><th>Amount</th><th>To pay</th><th></th></x-slot:head>
                    @foreach ($rules as $rule)
                        <tr>
                            <td class="text-slate-400">{{ $loop->iteration }}</td>
                            <td class="font-medium">
                                {{ $rule->rule_name }}
                                @unless ($rule->is_active) <x-admin.badge color="gray">Inactive</x-admin.badge> @endunless
                            </td>
                            <td>{{ $rule->paidBeforeModule ? 'Before “'.$rule->paidBeforeModule->title.'”' : 'At enrollment' }}</td>
                            <td>{{ $rule->amount_type->value === 'percent' ? rtrim(rtrim($rule->amount, '0'), '.').'%' : $taka($rule->amount) }}</td>
                            <td class="font-medium">{{ $taka($rule->amountFor($final)) }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit" aria-label="Edit installment"
                                    @click="open('rule', 'edit', '{{ route('admin.rules.update', $rule) }}', {{ \Illuminate\Support\Js::from([
                                        'rule_name' => $rule->rule_name, 'course_modules_id' => $rule->course_modules_id ?? '',
                                        'amount_type' => $rule->amount_type->value, 'amount' => (float) $rule->amount,
                                        'sort_order' => $rule->sort_order, 'is_active' => (bool) $rule->is_active,
                                    ]) }})">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <x-admin.delete-form :action="route('admin.rules.destroy', $rule)" confirm="Delete this installment?" label="Delete installment">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 text-sm">
                    <span class="text-slate-500">Active installments total <strong class="text-slate-900">{{ $taka($planTotal) }}</strong> of {{ $taka($final) }}</span>
                    @if ($planOk)
                        <x-admin.badge color="green">Plan matches the price</x-admin.badge>
                    @else
                        <x-admin.badge color="red">Plan does not match the price</x-admin.badge>
                    @endif
                </div>
            @endif
        </x-admin.card>
    @endif

    {{-- ------------------------------------------------------------ history --}}
    <x-admin.card>
        <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-semibold text-slate-900">Price history</h3></div>
        <x-admin.table>
            <x-slot:head><th>Date</th><th>Price</th><th>Discount</th><th>Final</th><th>Access</th><th>Renewal</th><th>Installments</th><th>Status</th></x-slot:head>
            @forelse ($priceHistory as $p)
                <tr>
                    <td>{{ $p->created_at?->format('d M Y, h:i A') }}</td>
                    <td>{{ $taka($p->actual_price) }}</td>
                    <td>{{ $p->discount_type ? ($p->discount_type->value === 'percent' ? rtrim(rtrim($p->discount_value, '0'), '.').'%' : $taka($p->discount_value)) : '—' }}</td>
                    <td class="font-medium">{{ $taka($p->finalPrice()) }}</td>
                    <td class="whitespace-nowrap">{{ $p->access_days }} days</td>
                    <td class="whitespace-nowrap">{{ $p->offersRenewal() ? $taka($p->renewal_fee).' / '.$p->renewal_days.' days' : '—' }}</td>
                    <td>{{ $p->payment_rules_count }}</td>
                    <td><x-admin.badge :color="$p->is_active ? 'green' : 'gray'">{{ $p->is_active ? 'Current' : 'Old' }}</x-admin.badge></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-slate-400">No prices yet.</td></tr>
            @endforelse
        </x-admin.table>
    </x-admin.card>

    {{-- ------------------------------------------------------------ price dialog --}}
    <x-admin.dialog show="dlg === 'price'" close="dlg = null" title="'Change price'" max-width="max-w-3xl">
        <form method="POST" :action="action" class="space-y-4">
            @csrf
            <input type="hidden" name="_modal" value="1">

            <div class="grid gap-6 md:grid-cols-2">
                {{-- left: access & renewal --}}
                <div class="space-y-4 md:order-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Access &amp; renewal</p>
                    <x-admin.input id="price_access" name="access_days" type="number" min="1" max="3650" label="Access (days)" x-model="form.access_days" required />
                    <x-admin.input id="price_rfee" name="renewal_fee" type="number" step="0.01" min="0" label="Renewal fee (৳)" x-model="form.renewal_fee" hint="Empty = renewal not offered." />
                    <x-admin.input id="price_rdays" name="renewal_days" type="number" min="1" max="3650" label="One renewal adds (days)" x-model="form.renewal_days" />
                </div>

                {{-- right: price --}}
                <div class="space-y-4 md:order-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Price</p>
                    <x-admin.input id="price_actual" name="actual_price" type="number" step="0.01" min="0" label="Price (৳)" x-model="form.actual_price" required />
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.select id="price_dtype" name="discount_type" label="Discount" x-model="form.discount_type">
                            <option value="">No discount</option>
                            <option value="percent">Percent (%)</option>
                            <option value="flat">Flat (৳)</option>
                        </x-admin.select>
                        <x-admin.input id="price_dvalue" name="discount_value" type="number" step="0.01" min="0" label="Discount value" x-model="form.discount_value" />
                    </div>
                    <x-admin.checkbox id="price_copy" name="copy_rules" label="Copy the current installments to the new price" x-model="form.copy_rules" />
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <x-admin.button type="button" variant="secondary" @click="dlg = null">Cancel</x-admin.button>
                <x-admin.button type="submit">Save price</x-admin.button>
            </div>
        </form>
    </x-admin.dialog>

    {{-- ------------------------------------------------------------ installment dialog --}}
    <x-admin.dialog show="dlg === 'rule'" close="dlg = null" title="mode === 'add' ? 'Add installment' : 'Edit installment'">
        <form method="POST" :action="action" class="space-y-4">
            @csrf
            <input type="hidden" name="_modal" value="1">
            <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">

            <x-admin.input id="rule_name" name="rule_name" label="Name" x-model="form.rule_name" placeholder="First installment" required />
            <x-admin.select id="rule_module" name="course_modules_id" label="Must be paid before" x-model="form.course_modules_id">
                <option value="">At enrollment</option>
                @foreach ($modules as $m)
                    <option value="{{ $m->id }}">{{ $m->title }}</option>
                @endforeach
            </x-admin.select>
            <div class="grid grid-cols-2 gap-3">
                <x-admin.select id="rule_type" name="amount_type" label="Amount type" x-model="form.amount_type">
                    <option value="percent">Percent (%)</option>
                    <option value="flat">Flat (৳)</option>
                </x-admin.select>
                <x-admin.input id="rule_amount" name="amount" type="number" step="0.01" min="0" label="Amount" x-model="form.amount" required />
            </div>
            <x-admin.input id="rule_order" name="sort_order" type="number" min="0" label="Order" x-model="form.sort_order" hint="Empty = last" />
            <x-admin.checkbox id="rule_active" name="is_active" label="Active" x-model="form.is_active" />

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <x-admin.button type="button" variant="secondary" @click="dlg = null">Cancel</x-admin.button>
                <x-admin.button type="submit">Save installment</x-admin.button>
            </div>
        </form>
    </x-admin.dialog>
</div>
