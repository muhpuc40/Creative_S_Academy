<x-admin.layout title="Manage Devices">
    <div x-data="deviceManager({ revokeUrl: '{{ route('admin.account.devices.revoke') }}' })">
        <x-admin.page-header title="Manage devices"
            description="Devices signed in to your account, and your recent login history." />

        {{-- Card 1: active devices --}}
        <x-admin.card title="Active sessions">
            <p class="mb-3 text-sm text-slate-500">Devices currently signed in. Signing out another device needs your password; this one signs out normally.</p>
            <div class="divide-y divide-slate-100">
                @forelse ($devices as $d)
                    @php($isCurrent = $d->device_id === $current)
                    @php($online = $d->last_used_at && $d->last_used_at->gt(now()->subMinutes(5)))
                    <div id="device-{{ $d->id }}" class="flex items-center justify-between gap-3 py-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                <x-admin.icon name="monitor" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 font-medium text-slate-900">
                                    <span class="truncate">{{ $d->device_name ?: 'Unknown device' }}</span>
                                    @if ($isCurrent)
                                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">This device</span>
                                    @elseif ($online)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500"></span>Online</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $d->ip_address ?: 'IP unknown' }} · last active {{ $d->last_used_at?->diffForHumans() ?? 'never' }}
                                </p>
                            </div>
                        </div>
                        <div class="shrink-0">
                            @if ($isCurrent)
                                <form method="POST" action="{{ route('admin.logout') }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">Log out</button>
                                </form>
                            @else
                                <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50"
                                    @click="askRevoke({{ $d->id }}, {{ \Illuminate\Support\Js::from($d->device_name ?: 'this device') }})">Log out</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">No active devices.</p>
                @endforelse
            </div>
        </x-admin.card>

        {{-- Card 2: login history --}}
        <x-admin.card title="Login history" class="mt-6">
            <p class="mb-3 text-sm text-slate-500">Your 50 most recent sign-in attempts.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4 font-medium">When</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 pr-4 font-medium">IP address</th>
                            <th class="py-2 font-medium">Device</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($history as $h)
                            @php($ok = $h->status === 'success')
                            <tr>
                                <td class="py-2.5 pr-4 whitespace-nowrap text-slate-700">{{ $h->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A') }}</td>
                                <td class="py-2.5 pr-4">
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
                                        'bg-emerald-50 text-emerald-700' => $ok,
                                        'bg-red-50 text-red-700' => ! $ok,
                                    ])>{{ $h->status }}</span>
                                    @if ($h->failure_reason)
                                        <span class="ml-1 text-xs text-slate-400">({{ str_replace('_', ' ', $h->failure_reason) }})</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 whitespace-nowrap text-slate-600">{{ $h->ip_address ?: '—' }}</td>
                                <td class="py-2.5 text-slate-600">{{ \Illuminate\Support\Str::limit($h->user_agent, 48) ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-slate-500">No login history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.card>

        {{-- Revoke-other-device confirm modal --}}
        <div x-show="revokeShow" x-cloak class="fixed inset-0 z-[70] overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-center justify-center p-4">
                <div x-show="revokeShow" x-transition.opacity class="fixed inset-0 bg-slate-900/60" @click="close()"></div>
                <div x-show="revokeShow" x-transition.scale.95 class="relative w-full max-w-md rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <h3 class="text-base font-semibold text-slate-900">Sign out device</h3>
                        <button type="button" class="rounded-md p-1 text-slate-400 hover:text-slate-600" @click="close()" aria-label="Close">
                            <x-admin.icon name="x" />
                        </button>
                    </div>
                    <form @submit.prevent="confirmRevoke()" class="space-y-4 px-6 py-5">
                        <p class="text-sm text-slate-600">Enter your password to sign out <span class="font-medium text-slate-900" x-text="revokeName"></span>.</p>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Your password</label>
                            <input type="password" x-model="password" autocomplete="current-password"
                                class="block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none">
                            <p x-show="revokeErr" x-text="revokeErr" class="mt-1.5 text-xs text-red-600"></p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <x-admin.button type="button" variant="secondary" @click="close()" x-bind:disabled="revokeBusy">Cancel</x-admin.button>
                            <x-admin.button type="submit" variant="danger" x-bind:disabled="revokeBusy">
                                <span x-text="revokeBusy ? 'Signing out…' : 'Sign out device'"></span>
                            </x-admin.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function deviceManager(cfg) {
                return {
                    revokeShow: false,
                    revokeId: null,
                    revokeName: '',
                    revokeBusy: false,
                    revokeErr: '',
                    password: '',

                    askRevoke(id, name) {
                        this.revokeId = id;
                        this.revokeName = name;
                        this.password = '';
                        this.revokeErr = '';
                        this.revokeShow = true;
                    },
                    close() { if (!this.revokeBusy) this.revokeShow = false; },
                    csrf() { return document.querySelector('meta[name=csrf-token]').content; },

                    async confirmRevoke() {
                        this.revokeBusy = true;
                        this.revokeErr = '';
                        try {
                            const r = await fetch(cfg.revokeUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                body: JSON.stringify({ device: this.revokeId, current_password: this.password }),
                            });
                            if (r.status === 422) {
                                const d = await r.json();
                                this.revokeErr = d.errors?.current_password?.[0] || d.errors?.device?.[0] || d.message || 'Please check your password.';
                                return;
                            }
                            if (r.status === 429) throw new Error('Too many attempts. Please wait a minute and try again.');
                            if (!r.ok) throw new Error('Request failed (' + r.status + ')');
                            document.getElementById('device-' + this.revokeId)?.remove();
                            this.revokeShow = false;
                        } catch (e) {
                            this.revokeErr = e.message;
                        } finally {
                            this.revokeBusy = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-admin.layout>
