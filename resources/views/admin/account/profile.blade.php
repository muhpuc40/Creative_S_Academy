<x-admin.layout title="My Profile">
    <x-admin.page-header title="My profile"
        description="Your account details. Update your name, email or phone number." />

    <x-admin.card title="My Information">
        <x-slot:actions>
            <span
                class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">Administrator</span>
            <span @class([
                'inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => ($admin->status->value ?? $admin->status) === 'active',
                'bg-red-50 text-red-700 ring-red-600/20' => ($admin->status->value ?? $admin->status) !== 'active',
            ])>{{ $admin->status->value ?? $admin->status }}</span>
        </x-slot:actions>

        <form method="POST" action="{{ route('admin.account.profile.update') }}" enctype="multipart/form-data"
            x-data="{ preview: {{ $admin->avatarUrl() ? \Illuminate\Support\Js::from($admin->avatarUrl()) : 'null' }}, removed: false }">
            @csrf
            @method('PUT')

            <div class="grid gap-6 md:grid-cols-[18rem_1fr]">

                {{-- left: square image + badges --}}
                <div class="flex flex-col rounded-xl bg-slate-50 p-4 ring-1 ring-slate-900/5">
                    <div class="w-full overflow-hidden rounded-lg bg-white ring-1 ring-slate-200">
                        <template x-if="preview">
                            <img :src="preview" alt="Profile image" class="aspect-square h-auto w-full object-cover">
                        </template>
                        <template x-if="!preview">
                            <span
                                class="flex aspect-square w-full items-center justify-center bg-indigo-600 text-6xl font-semibold uppercase text-white">{{ mb_substr($admin->name ?? 'A', 0, 1) }}</span>
                        </template>
                    </div>

                    {{-- upload controls --}}
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <label
                            class="cursor-pointer rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                            <span x-text="preview ? 'Change photo' : 'Upload photo'"></span>
                            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="hidden"
                                @change="if ($event.target.files[0]) { preview = URL.createObjectURL($event.target.files[0]); removed = false }">
                        </label>
                        <button type="button" x-show="preview"
                            @click="preview = null; removed = true; $root.querySelector('input[name=avatar]').value = ''"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Remove</button>
                    </div>
                    <p class="mt-1.5 text-center text-xs text-slate-500">JPG, PNG or WebP · max 2 MB.</p>

                    <input type="hidden" name="remove_avatar" :value="removed ? '1' : '0'">
                    @error('avatar')
                        <p class="mt-2 text-center text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- right: editable details --}}
                <div class="min-w-0 space-y-4">
                    <x-admin.input name="name" label="Name" :value="$admin->name" required />
                    <x-admin.input name="email" label="Email" type="email" :value="$admin->email" required />
                    <x-admin.input name="phone" label="Phone" :value="$admin->phone" required />

                    <div class="grid gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3">
                        <div class="sm:col-span-3">
                            <p class="text-slate-500">Public ID</p>
                            <p class="font-mono text-xs font-medium break-all text-slate-800">{{ $admin->public_id }}
                            </p>
                        </div>
                        <div>
                            <p class="text-slate-500">Last login</p>
                            <p class="font-medium text-slate-800">
                                {{ $admin->last_login_at?->timezone(config('app.timezone'))->format('d M Y, g:i A') ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-slate-500">Member since</p>
                            <p class="font-medium text-slate-800">
                                {{ $admin->created_at?->timezone(config('app.timezone'))->format('d M Y') ?? '—' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end border-t border-slate-100 pt-4">
                <x-admin.button type="submit">Save changes</x-admin.button>
            </div>
        </form>
    </x-admin.card>
</x-admin.layout>