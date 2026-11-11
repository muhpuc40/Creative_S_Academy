<x-admin.layout title="Upload content">
    <div x-data="{ open: false, mode: 'add', uploading: false, action: '{{ route('admin.library.content.store') }}', form: { name: '', url: '' } }"
         @uploader-busy.window="uploading = $event.detail">
        <x-admin.page-header title="Content library" description="Videos and files that classes, exams and assignments show. One content can be reused in many items.">
            <x-admin.button type="button" @click="mode = 'add'; action = '{{ route('admin.library.content.store') }}'; form = { name: '', url: '' }; $dispatch('uploader-reset'); open = true">
                <x-admin.icon name="upload" class="size-4" /> Upload content
            </x-admin.button>
        </x-admin.page-header>


        <x-admin.card>
            @if ($contents->isEmpty())
                <x-admin.empty-state icon="video" title="No content yet" message="Upload a video or paste a link." />
            @else
                <x-admin.table>
                    <x-slot:head><th>Content</th><th>Link / file</th><th>Used in</th><th>Updated</th><th class="text-right">Actions</th></x-slot:head>
                    @foreach ($contents as $content)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($content->thumbnail_url)
                                        <img src="{{ $content->thumbnail_url }}" alt="" class="h-10 w-16 rounded object-cover ring-1 ring-slate-200">
                                    @else
                                        <span class="flex h-10 w-16 items-center justify-center rounded bg-slate-100 text-slate-400"><x-admin.icon name="video" class="size-5" /></span>
                                    @endif
                                    <span class="font-medium text-slate-900">{{ $content->name }}</span>
                                </div>
                            </td>
                            <td>
                                @if ($content->hasStoredFile())
                                    <a href="{{ route('admin.library.content.file', $content) }}" target="_blank" rel="noopener" class="inline-flex max-w-xs items-center gap-1 truncate text-indigo-600 hover:underline">
                                        {{ $content->file_name }}
                                        <x-admin.icon name="external-link" class="size-3.5 shrink-0" /></a>
                                    <p class="text-xs text-slate-500">{{ $content->fileSizeForHumans() }} · private</p>
                                @elseif ($content->url)
                                    <a href="{{ $content->file_url }}" target="_blank" rel="noopener" class="inline-flex max-w-xs items-center gap-1 truncate text-indigo-600 hover:underline">
                                        {{ \App\Support\Media::isExternal($content->url) ? 'External link' : basename($content->url) }}
                                        <x-admin.icon name="external-link" class="size-3.5 shrink-0" /></a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td>{{ $content->items_count }} {{ Str::plural('item', $content->items_count) }}</td>
                            <td>{{ $content->updated_at }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit" aria-label="Edit content"
                                    @click="mode = 'edit'; action = '{{ route('admin.library.content.update', $content) }}'; form = {{ \Illuminate\Support\Js::from(['name' => $content->name, 'url' => \App\Support\Media::isExternal($content->url) ? $content->url : '']) }}; $dispatch('uploader-reset'); open = true">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <x-admin.delete-form :action="route('admin.library.content.destroy', $content)" confirm="Delete this content and its files?" label="Delete content">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>
        <div class="mt-6">{{ $contents->links() }}</div>

        <x-admin.dialog show="open" close="open = false" title="mode === 'add' ? 'Upload content' : 'Edit content'">
            <form method="POST" :action="action" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">
                <x-admin.input id="content_name" name="name" label="Name" x-model="form.name" required />
                <x-admin.input id="content_thumb" name="thumbnail" type="file" accept="image/*" label="Thumbnail" hint="JPG or PNG, up to 2 MB" />

                @include('admin.partials.chunkit-uploader', [
                    'id' => 'content_file',
                    'label' => 'Video / file',
                    'accept' => 'video/mp4,video/webm,video/quicktime,video/x-matroska,.mkv,application/pdf',
                    'hint' => 'MP4, WEBM, MOV, MKV or PDF, up to 2 GB. Uploads in pieces and resumes if the connection drops.',
                ])

                <x-admin.input id="content_url" name="url" label="…or paste a link" x-model="form.url" placeholder="https://" />
                <p x-show="mode === 'edit'" class="text-xs text-slate-500">Edit: leave file and link as they are to keep the current one.</p>
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
