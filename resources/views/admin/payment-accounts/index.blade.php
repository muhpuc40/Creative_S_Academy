@php
    $methodLabels = ['mfs' => 'Mobile', 'bank' => 'Bank', 'cash' => 'Cash'];
    $methodColors = ['mfs' => 'indigo', 'bank' => 'blue', 'cash' => 'amber'];
    $blank = ['method' => 'mfs', 'provider' => '', 'account_no' => '', 'account_name' => '', 'branch' => '', 'instructions' => '', 'sort_order' => 0, 'is_active' => true];
@endphp
<x-admin.layout title="Payment accounts">
    <div x-data="{ open: false, mode: 'add', action: '', form: {} }" @keydown.escape.window="open = false">
        <x-admin.page-header title="Payment accounts"
            description="Where students send money. Active accounts are shown to students, in this order, before they submit payment proof.">
            <x-admin.button type="button"
                @click="mode = 'add'; action = '{{ route('admin.payment-accounts.store') }}'; form = {{ \Illuminate\Support\Js::from($blank) }}; open = true">
                <x-admin.icon name="plus" class="size-4" /> Add account
            </x-admin.button>
        </x-admin.page-header>

        <x-admin.card>
            @if ($accounts->isEmpty())
                <x-admin.empty-state icon="wallet" title="No accounts yet" message="Add your bKash / Nagad number or bank account so students know where to pay." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Order</th>
                        <th>Name</th>
                        <th>Method</th>
                        <th>Account</th>
                        <th>Instructions</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($accounts as $account)
                        <tr @class(['opacity-60' => ! $account->is_active])>
                            <td class="text-slate-500">{{ $account->sort_order }}</td>
                            <td> <x-admin.badge :color="$methodColors[$account->method->value]" class="whitespace-nowrap">{{ $methodLabels[$account->method->value] }}</x-admin.badge></td>
                            <td>
                               
                                @if ($account->provider)
                                    <p class="mt-1 whitespace-nowrap text-sm font-medium text-slate-900">{{ $account->provider }}</p>
                                @endif
                            </td>
                            <td>
                                @if ($account->account_no)
                                    <p class="font-mono text-sm text-slate-900">{{ $account->account_no }}</p>
                                @endif
                                @if ($account->account_name)
                                    <p class="text-xs text-slate-500">{{ $account->account_name }}</p>
                                @endif
                                @if ($account->branch)
                                    <p class="text-xs text-slate-500">{{ $account->branch }}</p>
                                @endif
                            </td>
                            <td><p class="max-w-48 truncate text-xs text-slate-500" title="{{ $account->instructions }}">{{ $account->instructions ?: '—' }}</p></td>
                            <td>
                                @if ($account->is_active)
                                    <x-admin.badge color="green">Shown</x-admin.badge>
                                @else
                                    <x-admin.badge color="gray">Hidden</x-admin.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit"
                                    aria-label="Edit account"
                                    @click="mode = 'edit'; action = '{{ route('admin.payment-accounts.update', $account) }}'; form = {{ \Illuminate\Support\Js::from([
                                        'method' => $account->method->value,
                                        'provider' => $account->provider ?? '',
                                        'account_no' => $account->account_no ?? '',
                                        'account_name' => $account->account_name ?? '',
                                        'branch' => $account->branch ?? '',
                                        'instructions' => $account->instructions ?? '',
                                        'sort_order' => $account->sort_order,
                                        'is_active' => $account->is_active,
                                    ]) }}; open = true">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <form method="POST" action="{{ route('admin.payment-accounts.toggle', $account) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                        title="{{ $account->is_active ? 'Hide from students' : 'Show to students' }}"
                                        aria-label="{{ $account->is_active ? 'Hide from students' : 'Show to students' }}">
                                        <x-admin.icon :name="$account->is_active ? 'eye-off' : 'eye'" class="size-4" />
                                    </button>
                                </form>
                                @if ($account->payments_count === 0)
                                    <x-admin.delete-form :action="route('admin.payment-accounts.destroy', $account)"
                                        confirm="Delete this account?" label="Delete account">
                                        <x-admin.icon name="trash" class="size-4" />
                                    </x-admin.delete-form>
                                @else
                                    <span class="inline-flex cursor-not-allowed rounded-lg p-1.5 text-slate-300"
                                        title="Has payments - hide it instead of deleting">
                                        <x-admin.icon name="trash" class="size-4" />
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>

        <x-admin.dialog show="open" close="open = false" maxWidth="max-w-2xl" title="mode === 'add' ? 'Add account' : 'Edit account'">
            <form method="POST" :action="action" class="space-y-4">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.select id="acc_method" name="method" label="Method" x-model="form.method" required>
                        <option value="mfs">Mobile banking</option>
                        <option value="bank">Bank</option>
                        <option value="cash">Cash</option>
                    </x-admin.select>
                    <x-admin.input id="acc_sort" name="sort_order" type="number" min="0" x-model="form.sort_order" label="Order" />

                    <div x-show="form.method !== 'cash'">
                        <x-admin.input id="acc_provider" name="provider" x-model="form.provider" label="Provider / bank" />
                    </div>
                    <div x-show="form.method !== 'cash'">
                        <x-admin.input id="acc_no" name="account_no" x-model="form.account_no" label="Account number" inputmode="numeric" />
                    </div>

                    <x-admin.input id="acc_name" name="account_name" x-model="form.account_name" label="Account name" />
                    <div x-show="form.method === 'bank'">
                        <x-admin.input id="acc_branch" name="branch" x-model="form.branch" label="Branch" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-admin.input id="acc_instructions" name="instructions" x-model="form.instructions" label="Instructions" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                            class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                        <span>Show to students</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.dialog>
    </div>
</x-admin.layout>
