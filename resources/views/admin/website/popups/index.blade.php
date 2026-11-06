@php
    use App\Enums\PopupFrequency;
    use App\Enums\PopupKind;
    use Illuminate\Support\Js;

    $fileClass = 'block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200';
    $fieldClass = 'block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none';
    $kindColors = ['modal' => 'blue', 'corner' => 'amber'];
    $pagesLabel = ['all' => 'All pages', 'home' => 'Home only'];
    $blank = [
        'kind' => PopupKind::Modal->value,
        'title' => '',
        'body' => '',
        'button_text' => '',
        'link' => '',
        'image_url' => null,
        'pages_mode' => 'all',
        'paths' => '',
        'delay_seconds' => 3,
        'frequency' => PopupFrequency::PerVisit->value,
        'sort_order' => 0,
        'is_active' => true
    ];
@endphp
<x-admin.layout title="Popups">
    <div x-data="popupPage()" @keydown.escape.window="open = false; viewOpen = false">
        <x-admin.page-header title="Popups"
            description="Popups of the website: what they show, on which pages, after how many seconds and how often.">
            <x-admin.button type="button"
                @click="openForm('add', '{{ route('admin.website.popups.store') }}', {{ Js::from($blank) }})">
                <x-admin.icon name="plus" class="size-4" /> Add popup
            </x-admin.button>
        </x-admin.page-header>

        <x-admin.card>
            @if ($popups->isEmpty())
                <x-admin.empty-state icon="layers" title="No popups yet"
                    message="Add a modal (set How often to Once ever for a welcome popup) or a small corner image." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Kind</th>
                        <th>Popup</th>
                        <th>Pages</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($popups as $popup)
                        @php
                            $setting = $popup->setting;
                            $mode = $setting->pages_mode;
                            // one object for the View and the Edit window
                            $row = [
                                'kind' => $popup->kind->value,
                                'kind_label' => $popup->kind->label(),
                                'title' => $popup->title ?? '',
                                'body' => $popup->body ?? '',
                                'button_text' => $popup->button_text ?? '',
                                'link' => $popup->link ?? '',
                                'image_url' => $popup->image_url,
                                'pages_mode' => $mode,
                                'paths' => $mode === 'specific' ? implode("\n", $setting->pages) : '',
                                'pages_label' => $pagesLabel[$mode] ?? implode(', ', $setting->pages),
                                'delay_seconds' => $setting->delay_seconds,
                                'frequency' => $setting->frequency->value,
                                'frequency_label' => $setting->frequency->label(),
                                'sort_order' => $setting->sort_order,
                                'is_active' => $setting->is_active,
                                'updated' => $popup->updated_at->diffForHumans(),
                                'edit_url' => route('admin.website.popups.update', $popup),
                            ];
                        @endphp
                        <tr @class(['opacity-60' => !$setting->is_active])>
                            <td><x-admin.badge
                                    :color="$kindColors[$popup->kind->value]">{{ $popup->kind->label() }}</x-admin.badge></td>
                            <td>
                                <p class="max-w-56 truncate font-medium text-slate-900" title="{{ $popup->title }}">
                                    {{ $popup->kind->hasText() ? $popup->title : 'Image' }}
                                </p>
                            </td>
                            <td>
                                <p class="max-w-40 truncate text-sm text-slate-700" title="{{ $row['pages_label'] }}">
                                    {{ $row['pages_label'] }}
                                </p>
                            </td>
                            <td class="text-slate-500">{{ $setting->sort_order }}</td>
                            <td>
                                @if ($setting->is_active)
                                    <x-admin.badge color="green">On</x-admin.badge>
                                @else
                                    <x-admin.badge color="gray">Off</x-admin.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="View"
                                    aria-label="View popup" @click="openView({{ Js::from($row) }})">
                                    <x-admin.icon name="eye" class="size-4" />
                                </button>
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit"
                                    aria-label="Edit popup"
                                    @click="openForm('edit', '{{ $row['edit_url'] }}', {{ Js::from($row) }})">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <form method="POST" action="{{ route('admin.website.popups.toggle', $popup) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                        title="{{ $setting->is_active ? 'Turn off' : 'Turn on' }}"
                                        aria-label="{{ $setting->is_active ? 'Turn off' : 'Turn on' }}">
                                        <x-admin.icon :name="$setting->is_active ? 'eye-off' : 'check-circle'" class="size-4" />
                                    </button>
                                </form>
                                <x-admin.delete-form :action="route('admin.website.popups.destroy', $popup)"
                                    confirm="Delete this popup and its image?" label="Delete popup">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>

        {{-- View: everything about one popup, read only --}}
        <x-admin.dialog show="viewOpen" close="viewOpen = false" maxWidth="max-w-4xl"
            title="view.kind_label + ' popup'">
            <div class="grid gap-6 lg:grid-cols-2">
                <section>
                    <h3 class="mb-3 text-sm font-semibold text-slate-900">Content</h3>
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Image</dt>
                            <dd class="mt-1">
                                <img x-show="view.image_url" :src="view.image_url" alt=""
                                    class="max-h-48 rounded-lg object-contain ring-1 ring-slate-900/10">
                                <span x-show="! view.image_url" class="text-slate-500">None</span>
                            </dd>
                        </div>
                        <template x-if="view.kind !== 'corner'">
                            <div class="space-y-4">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Title</dt>
                                    <dd class="mt-1 font-medium text-slate-900" x-text="view.title"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Text</dt>
                                    <dd class="mt-1 whitespace-pre-line text-slate-700" x-text="view.body || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Button text
                                    </dt>
                                    <dd class="mt-1 text-slate-700" x-text="view.button_text || '—'"></dd>
                                </div>
                            </div>
                        </template>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500"
                                x-text="view.kind === 'corner' ? 'Link' : 'Button link'"></dt>
                            <dd class="mt-1 truncate font-mono text-xs text-slate-700" x-text="view.link || '—'"
                                :title="view.link"></dd>
                        </div>
                    </dl>
                </section>

                <section>
                    <h3 class="mb-3 text-sm font-semibold text-slate-900">Settings</h3>
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Pages</dt>
                            <dd x-show="view.pages_mode !== 'specific'" class="mt-1 text-slate-700"
                                x-text="view.pages_label"></dd>
                            <dd x-show="view.pages_mode === 'specific'"
                                class="mt-1 whitespace-pre-line font-mono text-xs text-slate-700" x-text="view.paths">
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Delay</dt>
                                <dd class="mt-1 text-slate-700"
                                    x-text="view.delay_seconds + ' seconds after arriving on the site'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">How often</dt>
                                <dd class="mt-1 text-slate-700" x-text="view.frequency_label"></dd>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Order</dt>
                                <dd class="mt-1 text-slate-700" x-text="view.sort_order"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</dt>
                                <dd class="mt-1">
                                    <x-admin.badge color="green" x-show="view.is_active">On</x-admin.badge>
                                    <x-admin.badge color="gray" x-show="! view.is_active">Off</x-admin.badge>
                                </dd>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">Updated <span x-text="view.updated"></span></p>
                    </dl>
                </section>
            </div>

            <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                <x-admin.button type="button" variant="secondary" @click="viewOpen = false">Close</x-admin.button>
                <x-admin.button type="button" @click="viewOpen = false; openForm('edit', view.edit_url, view)">
                    <x-admin.icon name="edit" class="size-4" /> Edit
                </x-admin.button>
            </div>
        </x-admin.dialog>

        {{-- Add / Edit: content on the left, settings on the right --}}
        <x-admin.dialog show="open" close="open = false" maxWidth="max-w-5xl"
            title="mode === 'add' ? 'Add popup' : 'Edit popup'">
            <form method="POST" :action="action" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">

                {{-- kind --}}
                <fieldset class="mb-6 grid gap-3 sm:grid-cols-2">
                    <legend class="mb-1.5 text-sm font-medium text-slate-700">Kind</legend>
                    @foreach (PopupKind::cases() as $kind)
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-inset ring-slate-200 has-[:checked]:bg-indigo-50/60 has-[:checked]:ring-indigo-500">
                            <input type="radio" name="kind" value="{{ $kind->value }}" x-model="form.kind"
                                class="mt-0.5 size-4 border-slate-300 text-indigo-600 focus:ring-indigo-600">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ $kind->label() }}</span>
                                <span class="block text-xs text-slate-500">{{ $kind->description() }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="grid gap-6 lg:grid-cols-2">
                    {{-- left: content --}}
                    <section class="space-y-4">
                        <h3 class="text-sm font-semibold text-slate-900">Content</h3>

                        <div>
                            <label for="popup_image" class="mb-1.5 block text-sm font-medium text-slate-700">
                                Image <span x-show="hasText()" class="font-normal text-slate-500">(optional)</span>
                            </label>
                            <div class="flex items-start gap-3">
                                <img x-show="imagePreview || (form.image_url && ! removeImage)"
                                    :src="imagePreview || form.image_url" alt=""
                                    class="size-16 shrink-0 rounded-lg object-cover ring-1 ring-slate-900/10">
                                <div class="min-w-0 flex-1">
                                    <input id="popup_image" name="image" type="file"
                                        accept="image/jpeg,image/png,image/webp" x-ref="image"
                                        :required="! hasText() && ! form.image_url"
                                        @change="imagePreview = fileUrl($event); removeImage = false"
                                        class="{{ $fileClass }}">
                                    <p class="mt-1.5 text-xs text-slate-500"
                                        x-text="form.image_url ? 'Choose a file only to replace the current image.' : 'JPG, PNG or WebP, up to 4 MB.'">
                                    </p>
                                    <label x-show="hasText() && form.image_url && ! imagePreview"
                                        class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                        <input type="checkbox" name="remove_image" value="1" x-model="removeImage"
                                            class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                        Remove the image
                                    </label>
                                </div>
                            </div>
                        </div>

                        <template x-if="hasText()">
                            <div class="space-y-4">
                                <x-admin.input id="popup_title" name="title" x-model="form.title" label="Title"
                                    maxlength="150" required />
                                <div>
                                    <label for="popup_body"
                                        class="mb-1.5 block text-sm font-medium text-slate-700">Text</label>
                                    <textarea id="popup_body" name="body" rows="3" maxlength="1000" x-model="form.body"
                                        class="{{ $fieldClass }}"></textarea>
                                </div>
                                <x-admin.input id="popup_button_text" name="button_text" x-model="form.button_text"
                                    label="Button text" maxlength="60" />
                            </div>
                        </template>

                        <div>
                            <label for="popup_link" class="mb-1.5 block text-sm font-medium text-slate-700"
                                x-text="hasText() ? 'Button link' : 'Link (optional, opens when the image is clicked)'"></label>
                            <input id="popup_link" name="link" x-model="form.link" placeholder="/courses or https://..."
                                class="{{ $fieldClass }}">
                        </div>
                    </section>

                    {{-- right: settings --}}
                    <section class="space-y-4">
                        <h3 class="text-sm font-semibold text-slate-900">Settings</h3>

                        <fieldset>
                            <legend class="mb-1.5 text-sm font-medium text-slate-700">Show on</legend>
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                @foreach (['all' => 'All pages', 'home' => 'Home only', 'specific' => 'Specific pages'] as $value => $label)
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="radio" name="pages_mode" value="{{ $value }}" x-model="form.pages_mode"
                                            class="size-4 border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <div x-show="form.pages_mode === 'specific'" class="mt-3">
                                <textarea name="paths" rows="4" x-model="form.paths" aria-label="Page paths"
                                    placeholder="/courses/*&#10;/courses/ui-ux"
                                    class="{{ $fieldClass }} font-mono"></textarea>
                                <p class="mt-1.5 text-xs text-slate-500">One path per line. <span
                                        class="font-mono">/courses/*</span> = every page under /courses.</p>
                            </div>
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-admin.input id="popup_delay" name="delay_seconds" type="number" min="0" max="600"
                                x-model="form.delay_seconds" label="Delay (seconds)"
                                hint="Counted from when the visitor arrives on the site." required />
                            <div>
                                <label for="popup_frequency" class="mb-1.5 block text-sm font-medium text-slate-700">How
                                    often</label>
                                <select id="popup_frequency" name="frequency" x-model="form.frequency"
                                    class="{{ $fieldClass }}">
                                    @foreach (PopupFrequency::cases() as $frequency)
                                        <option value="{{ $frequency->value }}">{{ $frequency->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-admin.input id="popup_sort" name="sort_order" type="number" min="0"
                                x-model="form.sort_order" label="Order"
                                hint="When two popups fit a page, the lower number shows." />
                            <label class="flex items-center gap-2 self-start pt-8 text-sm text-slate-700">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                                    class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                <span>On (show on the website)</span>
                            </label>
                        </div>
                    </section>
                </div>

                <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.dialog>
    </div>

    <script>
        function popupPage() {
            return {
                // add / edit window
                open: false, mode: 'add', action: '', form: {}, imagePreview: null, removeImage: false,
                // view window
                viewOpen: false, view: {},

                openForm(mode, action, data) {
                    this.mode = mode; this.action = action; this.form = { ...data };
                    this.imagePreview = null; this.removeImage = false;
                    this.$refs.image.value = '';
                    this.open = true;
                },
                openView(data) {
                    this.view = data;
                    this.viewOpen = true;
                },
                hasText() {
                    return this.form.kind !== 'corner';
                },
                fileUrl(event) {
                    const file = event.target.files[0];
                    return file ? URL.createObjectURL(file) : null;
                },
            };
        }
    </script>
</x-admin.layout>