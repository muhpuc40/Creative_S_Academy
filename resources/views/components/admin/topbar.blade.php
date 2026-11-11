@php($admin = auth()->user())
<header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
    <button type="button" class="-ml-1 rounded-md p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = true" aria-label="Open menu">
        <x-admin.icon name="menu" class="size-6" />
    </button>

    <div class="flex-1"></div>

    <div x-data="passwordModal({ url: '{{ route('admin.account.password') }}' })">
        <x-admin.dropdown align="right" width="w-60">
            <x-slot:trigger>
                <button type="button" class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-slate-100">
                    @if ($admin->avatarUrl())
                        <img src="{{ $admin->avatarUrl() }}" alt="" class="size-8 rounded-full object-cover">
                    @else
                        <span class="flex size-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold uppercase text-white">
                            {{ mb_substr($admin->name ?? 'A', 0, 1) }}
                        </span>
                    @endif
                    <span class="hidden text-sm font-medium text-slate-700 sm:block">{{ $admin->name }}</span>
                    <x-admin.icon name="chevron-down" class="size-4 text-slate-400" />
                </button>
            </x-slot:trigger>

            <div class="border-b border-slate-100 px-4 py-3">
                <p class="truncate text-sm font-medium text-slate-900">{{ $admin->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ $admin->email }}</p>
            </div>

            <a href="{{ route('admin.account.profile') }}" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                <x-admin.icon name="user" class="size-4 text-slate-400" /> My profile
            </a>
            <a href="{{ route('admin.account.devices') }}" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                <x-admin.icon name="monitor" class="size-4 text-slate-400" /> Manage devices
            </a>
            <button type="button" @click="openModal()" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                <x-admin.icon name="lock" class="size-4 text-slate-400" /> Change password
            </button>

            <div class="my-1 border-t border-slate-100"></div>

            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                    <x-admin.icon name="log-out" class="size-4 text-slate-400" /> Log out
                </button>
            </form>
        </x-admin.dropdown>

        {{-- Change password modal (AJAX; stays open on error) --}}
        <div x-show="show" x-cloak class="fixed inset-0 z-[70] overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-center justify-center p-4">
                <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/60" @click="close()"></div>

                <div x-show="show" x-transition.scale.95 class="relative w-full max-w-md rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <x-admin.icon name="lock" class="size-5 text-indigo-600" /> Change password
                        </h3>
                        <button type="button" class="rounded-md p-1 text-slate-400 hover:text-slate-600" @click="close()" aria-label="Close">
                            <x-admin.icon name="x" />
                        </button>
                    </div>

                    <form @submit.prevent="save()" class="space-y-4 px-6 py-5">
                        <div x-show="done" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">Password changed.</div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Current password</label>
                            <input type="password" x-model="form.current_password" autocomplete="current-password"
                                class="block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none">
                            <p x-show="err('current_password')" x-text="err('current_password')" class="mt-1.5 text-xs text-red-600"></p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">New password</label>
                            <input type="password" x-model="form.password" autocomplete="new-password"
                                class="block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none">
                            <p x-show="err('password')" x-text="err('password')" class="mt-1.5 text-xs text-red-600"></p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Confirm new password</label>
                            <input type="password" x-model="form.password_confirmation" autocomplete="new-password"
                                class="block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none">
                        </div>
                        <p x-show="err('general')" x-text="err('general')" class="text-xs text-red-600"></p>

                        <div class="flex justify-end gap-2 pt-1">
                            <x-admin.button type="button" variant="secondary" @click="close()" x-bind:disabled="busy">Cancel</x-admin.button>
                            <x-admin.button type="submit" x-bind:disabled="busy">
                                <span x-text="busy ? 'Saving…' : 'Change password'"></span>
                            </x-admin.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @once
        @push('scripts')
            <script>
                function passwordModal(cfg) {
                    return {
                        show: false,
                        busy: false,
                        done: false,
                        errors: {},
                        form: { current_password: '', password: '', password_confirmation: '' },

                        openModal() {
                            this.form = { current_password: '', password: '', password_confirmation: '' };
                            this.errors = {};
                            this.done = false;
                            this.show = true;
                        },
                        close() { if (!this.busy) this.show = false; },
                        err(f) { return this.errors[f] ? this.errors[f][0] : ''; },
                        csrf() { return document.querySelector('meta[name=csrf-token]').content; },

                        async save() {
                            this.busy = true;
                            this.errors = {};
                            try {
                                const r = await fetch(cfg.url, {
                                    method: 'PUT',
                                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                    body: JSON.stringify(this.form),
                                });
                                if (r.status === 422) { this.errors = (await r.json()).errors || {}; return; }
                                if (!r.ok) throw new Error('Request failed (' + r.status + ')');
                                this.done = true;
                                setTimeout(() => { this.show = false; }, 1200);
                            } catch (e) {
                                this.errors = { general: [e.message] };
                            } finally {
                                this.busy = false;
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endonce
</header>
