<x-admin.layout title="Meta Pixel">
    <x-admin.page-header title="Meta Pixel"
        description="The Facebook / Meta Pixel on the student website: page views, course views, checkout and sign-up events. Changes work without a redeploy." />

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <x-admin.card title="Pixel" class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.meta-pixel.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <x-admin.input name="pixel_id" label="Pixel ID" :value="$settings->pixel_id" inputmode="numeric" maxlength="20" autocomplete="off"
                    placeholder="e.g. 1234567890123456"
                    hint="Meta Events Manager > Data sources > your pixel: the number under its name." />

                <x-admin.checkbox name="is_active" label="Pixel on (the website loads it)" :checked="$settings->is_active" />

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

        <x-admin.card title="Status">
            @if ($settings->is_active && $settings->pixel_id)
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full bg-emerald-500"></span>
                    <span class="font-medium text-slate-900">On</span>
                </div>
                <p class="mt-2 text-sm text-slate-500">The website sends events to pixel <span class="font-mono text-slate-700">{{ $settings->pixel_id }}</span>.</p>
            @else
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full bg-slate-300"></span>
                    <span class="font-medium text-slate-900">Off</span>
                </div>
                <p class="mt-2 text-sm text-slate-500">No pixel is loaded on the website.</p>
            @endif
            <p class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
                The website keeps this for a few minutes, so a change shows there shortly after saving.
                Check it with the Meta Pixel Helper browser extension.
            </p>
        </x-admin.card>
    </div>
</x-admin.layout>
