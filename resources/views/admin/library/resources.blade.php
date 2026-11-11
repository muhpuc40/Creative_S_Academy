<x-admin.layout title="Upload resource">
    <div
        x-data="{ open: false, mode: 'add', uploading: false, action: '{{ route('admin.library.resources.store') }}', form: { name: '', url: '' } }"
        @uploader-busy.window="uploading = $event.detail">
        <x-admin.page-header title="Resource library"
            description="Slides, PDFs and other files students can use. One resource can be reused in many items.">
            <x-admin.button type="button"
                @click="mode = 'add'; action = '{{ route('admin.library.resources.store') }}'; form = { name: '', url: '' }; $dispatch('uploader-reset'); open = true">
                <x-admin.icon name="upload" class="size-4" /> Upload resource
            </x-admin.button>
        </x-admin.page-header>


        <x-admin.card>
            @if ($resources->isEmpty())
                <x-admin.empty-state icon="folder" title="No resources yet" message="Upload a file or paste a link." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Resource</th>
                        <th>Link / file</th>
                        <th>Used in</th>
                        <th>Updated</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($resources as $resource)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="font-medium text-slate-900">{{ $resource->name }}</span>
                                </div>
                            </td>
                            <td><a href="{{ $resource->file_url }}" target="_blank" rel="noopener"
                                    class="inline-flex max-w-xs items-center gap-1 truncate text-indigo-600 hover:underline">
                                    {{ \App\Support\Media::isExternal($resource->url) ? 'External link' : basename($resource->url) }}
                                    <x-admin.icon name="external-link" class="size-3.5 shrink-0" /></a></td>
                            <td>{{ $resource->items_count }} {{ Str::plural('item', $resource->items_count) }}</td>
                            <td>{{ $resource->updated_at }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit"
                                    aria-label="Edit resource"
                                    @click="mode = 'edit'; action = '{{ route('admin.library.resources.update', $resource) }}'; form = {{ \Illuminate\Support\Js::from(['name' => $resource->name, 'url' => \App\Support\Media::isExternal($resource->url) ? $resource->url : '']) }}; $dispatch('uploader-reset'); open = true">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <x-admin.delete-form :action="route('admin.library.resources.destroy', $resource)"
                                    confirm="Delete this resource and its file?" label="Delete resource">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>
        <div class="mt-6">{{ $resources->links() }}</div>

        <x-admin.dialog show="open" close="open = false" title="mode === 'add' ? 'Upload resource' : 'Edit resource'">
            <form method="POST" :action="action" class="space-y-4">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">
                <x-admin.input id="res_name" name="name" label="Name" x-model="form.name" required />
                @include('admin.partials.chunkit-uploader', [
                    'id' => 'res_file',
                    'label' => 'File',
                    'accept' => implode(',', array_map(fn ($ext) => ".{$ext}", array_keys($limits))),
                    'hint' => 'Formats and sizes from System > File types. Uploads in pieces and resumes if the connection drops.',
                    'limits' => $limits,
                ])
                <x-admin.input id="res_url" name="url" label="…or paste a link" x-model="form.url"
                    placeholder="https://" />
                <p x-show="mode === 'edit'" class="text-xs text-slate-500">Edit: leave file and link as they are to keep
                    the current one.</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                    <x-admin.button type="submit" ::disabled="uploading">
                        <span x-text="uploading ? 'Wait for the upload…' : 'Save'">Save</span>
                    </x-admin.button>
                </div>
            </form>
        </x-admin.dialog>
    </div>
</x-admin.layout>
