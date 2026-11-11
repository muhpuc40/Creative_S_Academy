<x-admin.layout title="File types">
    @php($blank = ['name' => '', 'extensions' => '', 'max_size_mb' => 50, 'is_active' => true])
    @php($failed = $errors->any())
    @php($seed = $failed ? ['name' => old('name', ''), 'extensions' => old('extensions', ''), 'max_size_mb' => (int) old('max_size_mb', 50), 'is_active' => (bool) old('is_active', true)] : $blank)
    <div x-data="{
            open: {{ $failed ? 'true' : 'false' }},
            mode: '{{ $failed ? old('_form_mode', 'add') : 'add' }}',
            action: '{{ $failed ? old('_form_action', route('admin.file-types.store')) : route('admin.file-types.store') }}',
            form: {{ \Illuminate\Support\Js::from($seed) }}
         }">
        <x-admin.page-header title="File types"
            description="Formats allowed in the resource library, assignment files and email attachments. Changes work at once.">
            <x-admin.button type="button"
                @click="mode = 'add'; action = '{{ route('admin.file-types.store') }}'; form = {{ \Illuminate\Support\Js::from($blank) }}; open = true">
                <x-admin.icon name="plus" class="size-4" /> Add file type
            </x-admin.button>
        </x-admin.page-header>

        <x-admin.card :padded="false">
            @if ($types->isEmpty())
                <x-admin.empty-state icon="file" title="No file types" message="Nothing can be uploaded until you add one." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Name</th>
                        <th>Extensions</th>
                        <th>Max size</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($types as $type)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $type->name }}</td>
                            <td>
                                <div class="flex min-w-56 max-w-xl flex-wrap gap-1">
                                    @foreach ($type->extensions as $ext)
                                        <x-admin.badge>.{{ $ext }}</x-admin.badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="whitespace-nowrap">{{ $type->max_size_mb }} MB</td>
                            <td>
                                <x-admin.badge :color="$type->is_active ? 'green' : 'gray'">{{ $type->is_active ? 'Active' : 'Off' }}</x-admin.badge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit"
                                    aria-label="Edit file type"
                                    @click="mode = 'edit'; action = '{{ route('admin.file-types.update', $type) }}'; form = {{ \Illuminate\Support\Js::from(['name' => $type->name, 'extensions' => implode(', ', $type->extensions), 'max_size_mb' => $type->max_size_mb, 'is_active' => $type->is_active]) }}; open = true">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <x-admin.delete-form :action="route('admin.file-types.destroy', $type)"
                                    confirm="Delete this file type? Its extensions can no longer be uploaded (unless another type has them)." label="Delete file type">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>

        <x-admin.dialog show="open" close="open = false" title="mode === 'add' ? 'Add file type' : 'Edit file type'">
            <form method="POST" :action="action" class="space-y-4">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">
                <input type="hidden" name="_form_mode" :value="mode">
                <input type="hidden" name="_form_action" :value="action">
                <x-admin.input id="ft_name" name="name" label="Name" x-model="form.name" placeholder="e.g. CLO3D" required />
                <x-admin.textarea id="ft_extensions" name="extensions" label="Extensions" rows="3" x-model="form.extensions"
                    placeholder="zprj, zpac, obj, fbx" required />
                @foreach ($errors->get('extensions.*') as $messages)
                    @foreach ($messages as $message)
                        <p class="-mt-2 text-xs text-red-600">{{ $message }}</p>
                    @endforeach
                @endforeach
                <x-admin.input id="ft_max" name="max_size_mb" type="number" min="1" :max="$maxMb" label="Max size (MB)"
                    x-model="form.max_size_mb" required />
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                        class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                    <span>Active</span>
                </label>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.dialog>
    </div>
</x-admin.layout>
