<x-admin.layout title="SMS">
    <x-admin.page-header title="SMS"
        description="The SMS gateway used for login codes, notifications and Quick SMS. Changes work at once, no redeploy." />

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <x-admin.card title="SMS gateway" class="lg:col-span-2">
            @unless ($settings->sends())
                <x-admin.alert type="warning" class="mb-4">Driver is "Log only": SMS are written to the log file and nobody receives them.</x-admin.alert>
            @endunless

            <form method="POST" action="{{ route('admin.sms-settings.update') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.select name="driver" label="Driver" :value="$settings->driver">
                        @foreach (\App\Models\SmsSetting::DRIVERS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-admin.select>
                    <x-admin.input name="url" label="API URL" :value="$settings->url" required />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input name="sender_id" label="Sender ID" :value="$settings->sender_id" />
                    <x-admin.input name="api_key" type="password" label="API key" autocomplete="off"
                        :placeholder="$settings->api_key ? str($settings->api_key)->mask('*', 0, -4) : ''" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input name="rate" type="number" step="0.01" min="0" label="Rate per SMS part (৳)" :value="$settings->rate" required
                        hint="Only for the cost estimate in Quick SMS." />
                    <x-admin.input name="bulk_chunk" type="number" min="1" max="1000" label="Numbers per API call" :value="$settings->bulk_chunk" required
                        hint="Quick SMS sends in groups of this size." />
                </div>
                <div class="flex items-center justify-between gap-4 border-t border-slate-100 pt-4">
                    <p class="text-xs text-slate-500">
                        @if ($settings->updatedBy)
                            Last changed by {{ $settings->updatedBy->name }}, {{ $settings->updated_at->format('d M Y, h:i A') }}
                        @endif
                    </p>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.card>

        <x-admin.card title="Balance">
            @if ($settings->api_key)
                <div x-data="{
                        loading: true, data: null, error: null,
                        async load() {
                            this.loading = true; this.error = null;
                            try {
                                const res = await fetch(@js(route('admin.sms-settings.balance')), { headers: { Accept: 'application/json' } });
                                const json = await res.json();
                                res.ok ? this.data = json : this.error = json.message || 'Could not load the balance.';
                            } catch (e) { this.error = 'Could not reach the server.'; }
                            this.loading = false;
                        },
                    }" x-init="load()">
                    <p x-show="loading" class="text-sm text-slate-500">Loading...</p>
                    <template x-if="!loading && data">
                        <div>
                            <p class="text-3xl font-semibold" :class="data.low ? 'text-red-600' : 'text-slate-900'">৳<span x-text="data.balance"></span></p>
                            <p x-show="data.sms" class="mt-1 text-sm text-slate-500">About <span x-text="data.sms"></span> SMS parts at the saved rate</p>
                        </div>
                    </template>
                    <p x-show="!loading && error" x-text="error" class="text-sm text-red-600"></p>
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <x-admin.button type="button" variant="secondary" x-on:click="load()" x-bind:disabled="loading">Refresh</x-admin.button>
                    </div>
                </div>
            @else
                <p class="text-sm text-slate-500">Save the API key to see the balance.</p>
            @endif
        </x-admin.card>
    </div>
</x-admin.layout>
