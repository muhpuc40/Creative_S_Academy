<x-admin.layout title="Combo offers">
    <div x-data="{ viewing: null }" @keydown.escape.window="viewing = null">
        <x-admin.page-header title="Combo offers" description="Several courses sold together for one price.">
            <x-admin.button :href="route('admin.combos.create')">
                <x-admin.icon name="plus" class="size-4" /> New combo
            </x-admin.button>
        </x-admin.page-header>

        <form method="GET" action="{{ route('admin.combos.index') }}"
            class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_auto]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by title..."
                class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600">

            <select name="status"
                class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-600">
                <option value="">Any status</option>
                <option value="active" @selected($filter === 'active')>Active</option>
                <option value="inactive" @selected($filter === 'inactive')>Turned off</option>
                <option value="expired" @selected($filter === 'expired')>Expired</option>
            </select>

            <div class="flex gap-2">
                <x-admin.button type="submit">Filter</x-admin.button>
                @if (request()->hasAny(['q', 'status']))
                    <x-admin.button :href="route('admin.combos.index')" variant="secondary">Reset</x-admin.button>
                @endif
            </div>
        </form>

        <x-admin.card :padded="false">
            @if ($combos->isEmpty())
                <x-admin.empty-state icon="percent" :title="request()->hasAny(['q', 'status']) ? 'No combo matches your filter' : 'No combo offers yet'"
                    message="Bundle a few courses together and give them one price or a percent off.">
                </x-admin.empty-state>
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Title</th>
                        <th>Courses</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($combos as $combo)
                        @php
                            $sum = $combo->coursesTotal();
                            $final = $combo->finalPrice();
                            $status = $combo->isExpired() ? 'expired' : ($combo->is_active ? 'active' : 'inactive');
                            $color = ['active' => 'green', 'expired' => 'red', 'inactive' => 'gray'][$status];
                            $comboCourses = $combo->courses->map(fn($c) => [
                                'title' => $c->title,
                                'price' => $c->currentPrice?->finalPrice(),
                            ])->values();
                        @endphp
                        <tr>
                            <td>
                                <p class="font-medium text-slate-900">{{ $combo->title }}</p>
                                <p class="text-xs text-slate-400">/{{ $combo->slug }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $combo->price_type->value === 'flat' ? 'Fixed price' : $combo->price_value . '% off' }}
                                </p>
                            </td>
                            <td>
                                <button type="button"
                                    @click="viewing = { title: @js($combo->title), courses: @js($comboCourses) }"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-slate-700 hover:bg-slate-100"
                                    title="View courses" aria-label="View courses in this combo">
                                    <span class="font-medium">{{ $combo->courses_count }} courses</span>
                                    <x-admin.icon name="eye" class="size-4 text-slate-400" />
                                </button>
                            </td>
                            <td>
                                <p class="font-medium text-slate-900">Tk {{ number_format($final) }}</p>
                                @if ($sum > $final)
                                    <p class="text-xs text-slate-400 line-through">Tk {{ number_format($sum) }}</p>
                                @endif
                            </td>
                            <td><x-admin.badge :color="$color">{{ ucfirst($status) }}</x-admin.badge></td>
                            <td class="text-slate-500">{{ $combo->ends_at->format('d M Y') }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center justify-end gap-1">
                                    <a href="{{ route('admin.combos.edit', $combo) }}"
                                        class="inline-flex items-center justify-center rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                        title="Edit" aria-label="Edit combo">
                                        <x-admin.icon name="edit" class="size-4" />
                                    </a>
                                    <x-admin.delete-form :action="route('admin.combos.destroy', $combo)"
                                        confirm="Delete this combo offer?" label="Delete combo">
                                        <x-admin.icon name="trash" class="size-4" />
                                    </x-admin.delete-form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>

        @if ($combos->hasPages())
            <div class="mt-4">{{ $combos->links() }}</div>
        @endif

        <x-admin.dialog show="viewing !== null" close="viewing = null" title="(viewing?.title ?? '') + ' — courses'"
            maxWidth="max-w-lg">
            <template x-if="viewing">
                <ul class="divide-y divide-slate-100">
                    <template x-for="(course, i) in viewing.courses" :key="i">
                        <li class="flex items-center justify-between gap-4 py-2.5">
                            <span class="text-slate-700" x-text="course.title"></span>
                            <span class="shrink-0 font-medium text-slate-900"
                                x-text="course.price !== null ? 'Tk ' + Number(course.price).toLocaleString() : '—'"></span>
                        </li>
                    </template>
                </ul>
            </template>
            <div class="mt-4 flex justify-end border-t border-slate-100 pt-4">
                <x-admin.button type="button" variant="secondary" @click="viewing = null">Close</x-admin.button>
            </div>
        </x-admin.dialog>
    </div>
</x-admin.layout>