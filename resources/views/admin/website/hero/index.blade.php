@php
    $nextOrder = ($heroes->max('sort_order') ?? 0) + 1;
    $fileClass = 'block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200';
    $blank = ['title' => '', 'subtitle' => '', 'button_text' => '', 'button_path' => '', 'bg_color' => '', 'bg_image_url' => null,
        'sort_order' => $nextOrder, 'is_active' => true, 'image_url' => null];
@endphp
<x-admin.layout title="Hero content">
    <div x-data="heroPage()" @keydown.escape.window="open = false; viewOpen = false">
        <x-admin.page-header title="Hero content"
            description="Slides of the home page hero. Every slide marked Shown is in the slider, in this order.">
            <x-admin.button type="button" @click="openForm('add', '{{ route('admin.website.hero.store') }}', {{ \Illuminate\Support\Js::from($blank) }})">
                <x-admin.icon name="plus" class="size-4" /> Add slide
            </x-admin.button>
        </x-admin.page-header>

        @unless ($sliderIsOn)
            <x-admin.alert type="info" class="mb-6">
                The website shows the course list right now, not these slides.
                <a href="{{ route('admin.website.hero-settings.edit') }}" class="font-semibold underline">Change it in Hero settings</a>.
            </x-admin.alert>
        @endunless

        <x-admin.card>
            @if ($heroes->isEmpty())
                <x-admin.empty-state icon="image" title="No slides yet"
                    message="Add an image, a title and a button. Then choose 'Hero content slider' in Hero settings." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th>Order</th>
                        <th>Title</th>
                        <th>Button</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </x-slot:head>
                    @foreach ($heroes as $hero)
                        @php
                            // one object for the View and the Edit popup
                            $row = [
                                'title' => $hero->title,
                                'subtitle' => $hero->subtitle ?? '',
                                'button_text' => $hero->button_text ?? '',
                                'button_path' => $hero->button_path ?? '',
                                'bg_color' => $hero->bg_color ?? '',
                                'bg_image_url' => $hero->bg_image_url,
                                'sort_order' => $hero->sort_order,
                                'is_active' => $hero->is_active,
                                'image_url' => $hero->image_url,
                                'updated' => $hero->updated_at->diffForHumans(),
                                'edit_url' => route('admin.website.hero.update', $hero),
                            ];
                        @endphp
                        <tr @class(['opacity-60' => ! $hero->is_active])>
                            <td class="text-slate-500">{{ $hero->sort_order }}</td>
                            <td>
                                <p class="max-w-md truncate font-medium text-slate-900" title="{{ $hero->title }}">{{ $hero->title }}</p>
                            </td>
                            <td>
                                @if ($hero->button_text)
                                    <p class="whitespace-nowrap text-sm text-slate-900">{{ $hero->button_text }}</p>
                                    <p class="max-w-48 truncate font-mono text-xs text-slate-500" title="{{ $hero->button_path }}">{{ $hero->button_path }}</p>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($hero->is_active)
                                    <x-admin.badge color="green">Shown</x-admin.badge>
                                @else
                                    <x-admin.badge color="gray">Hidden</x-admin.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                    title="View" aria-label="View slide" @click="openView({{ \Illuminate\Support\Js::from($row) }})">
                                    <x-admin.icon name="eye" class="size-4" />
                                </button>
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                    title="Edit" aria-label="Edit slide" @click="openForm('edit', '{{ $row['edit_url'] }}', {{ \Illuminate\Support\Js::from($row) }})">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <form method="POST" action="{{ route('admin.website.hero.toggle', $hero) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                        title="{{ $hero->is_active ? 'Take out of the slider' : 'Put in the slider' }}"
                                        aria-label="{{ $hero->is_active ? 'Take out of the slider' : 'Put in the slider' }}">
                                        <x-admin.icon :name="$hero->is_active ? 'eye-off' : 'check-circle'" class="size-4" />
                                    </button>
                                </form>
                                <x-admin.delete-form :action="route('admin.website.hero.destroy', $hero)"
                                    confirm="Delete this slide and its images?" label="Delete slide">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.card>

        {{-- View: everything about one slide, read only --}}
        <x-admin.dialog show="viewOpen" close="viewOpen = false" maxWidth="max-w-4xl" title="'Slide'">
            <div class="grid gap-6 lg:grid-cols-2">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Title</dt>
                        <dd class="mt-1 font-medium text-slate-900" x-text="view.title"></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Subtitle</dt>
                        <dd class="mt-1 text-slate-700" x-text="view.subtitle || '—'"></dd>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Button text</dt>
                            <dd class="mt-1 text-slate-700" x-text="view.button_text || '—'"></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Button link</dt>
                            <dd class="mt-1 truncate font-mono text-xs text-slate-700" x-text="view.button_path || '—'" :title="view.button_path"></dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Slide image</dt>
                            <dd class="mt-1"><img :src="view.image_url" alt="" class="aspect-[4/3] w-full rounded-lg object-cover ring-1 ring-slate-900/10"></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Background image</dt>
                            <dd class="mt-1">
                                <img x-show="view.bg_image_url" :src="view.bg_image_url" alt="" class="aspect-[4/3] w-full rounded-lg object-cover ring-1 ring-slate-900/10">
                                <span x-show="! view.bg_image_url" class="text-slate-500">None</span>
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Background color</dt>
                            <dd class="mt-1 flex items-center gap-2">
                                <span x-show="view.bg_color" class="size-5 rounded ring-1 ring-slate-900/10" :style="`background:${view.bg_color}`"></span>
                                <span class="font-mono text-xs text-slate-700" x-text="view.bg_color || 'None'"></span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Order</dt>
                            <dd class="mt-1 text-slate-700" x-text="view.sort_order"></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</dt>
                            <dd class="mt-1">
                                <x-admin.badge color="green" x-show="view.is_active">Shown</x-admin.badge>
                                <x-admin.badge color="gray" x-show="! view.is_active">Hidden</x-admin.badge>
                            </dd>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Updated <span x-text="view.updated"></span></p>
                </dl>

                <div>
                    <p class="mb-1.5 text-sm font-medium text-slate-700">Preview</p>
                    @include('admin.website.hero._preview', ['slide' => 'viewSlide()'])
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                <x-admin.button type="button" variant="secondary" @click="viewOpen = false">Close</x-admin.button>
                <x-admin.button type="button" @click="viewOpen = false; openForm('edit', view.edit_url, view)">
                    <x-admin.icon name="edit" class="size-4" /> Edit
                </x-admin.button>
            </div>
        </x-admin.dialog>

        {{-- Add / Edit: inputs on the left, live preview on the right --}}
        <x-admin.dialog show="open" close="open = false" maxWidth="max-w-5xl" title="mode === 'add' ? 'Add slide' : 'Edit slide'">
            <form method="POST" :action="action" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">
                <input type="hidden" name="_modal" value="1">

                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    {{-- left: the data --}}
                    <div class="grid grid-cols-1 content-start gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-admin.input id="hero_title" name="title" x-model="form.title" label="Title" maxlength="255" required />
                        </div>
                        <div class="sm:col-span-2">
                            <x-admin.input id="hero_subtitle" name="subtitle" x-model="form.subtitle" label="Subtitle" maxlength="500" />
                        </div>

                        <x-admin.input id="hero_button_text" name="button_text" x-model="form.button_text" label="Button text" maxlength="60" />
                        <x-admin.input id="hero_button_path" name="button_path" x-model="form.button_path" label="Button link" />

                        <div class="sm:col-span-2">
                            <label for="hero_image" class="mb-1.5 block text-sm font-medium text-slate-700">Slide image</label>
                            <input id="hero_image" name="image" type="file" accept="image/jpeg,image/png,image/webp" x-ref="image"
                                :required="mode === 'add'" @change="imagePreview = fileUrl($event)" class="{{ $fileClass }}">
                            <p class="mt-1.5 text-xs text-slate-500" x-text="mode === 'add' ? 'JPG, PNG or WebP, up to 4 MB.' : 'Choose a file only to replace the current image.'"></p>
                        </div>

                        {{-- background: a color, an image, both or neither --}}
                        <div>
                            <label for="hero_bg_color" class="mb-1.5 block text-sm font-medium text-slate-700">Background color</label>
                            <div class="flex items-center gap-2">
                                <input type="color" aria-label="Pick a color" :value="form.bg_color || '#f7f3fe'"
                                    @input="form.bg_color = $event.target.value"
                                    class="h-9 w-12 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                                <input id="hero_bg_color" name="bg_color" x-model="form.bg_color" placeholder="None" maxlength="7"
                                    class="block w-full min-w-0 rounded-lg border-0 px-3 py-2 font-mono text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 focus:outline-none">
                                <button type="button" x-show="form.bg_color" @click="form.bg_color = ''"
                                    class="shrink-0 text-xs font-medium text-slate-500 hover:text-slate-800">Clear</button>
                            </div>
                        </div>
                        <div>
                            <label for="hero_bg_image" class="mb-1.5 block text-sm font-medium text-slate-700">Background image</label>
                            <input id="hero_bg_image" name="bg_image" type="file" accept="image/jpeg,image/png,image/webp" x-ref="bgImage"
                                @change="bgPreview = fileUrl($event); removeBg = false" class="{{ $fileClass }}">
                            <label x-show="mode === 'edit' && form.bg_image_url && ! bgPreview" class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                <input type="checkbox" name="remove_bg_image" value="1" x-model="removeBg"
                                    class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                Remove the background image
                            </label>
                        </div>

                        <x-admin.input id="hero_sort" name="sort_order" type="number" min="0" x-model="form.sort_order" label="Order" />

                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                                class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                            <span>Show in the slider</span>
                        </label>
                    </div>

                    {{-- right: live preview --}}
                    <div class="lg:sticky lg:top-0 lg:self-start">
                        <p class="mb-1.5 text-sm font-medium text-slate-700">Preview</p>
                        @include('admin.website.hero._preview', ['slide' => 'formSlide()'])
                        <p class="mt-2 text-xs text-slate-500">Updates as you type. On the website the slide fills the full width.</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.dialog>
    </div>

    <script>
        function heroPage() {
            return {
                // add / edit popup
                open: false, mode: 'add', action: '', form: {},
                imagePreview: null, bgPreview: null, removeBg: false,
                // view popup
                viewOpen: false, view: {},

                openForm(mode, action, data) {
                    this.mode = mode; this.action = action; this.form = { ...data };
                    this.imagePreview = this.bgPreview = null; this.removeBg = false;
                    this.$refs.image.value = ''; this.$refs.bgImage.value = '';
                    this.open = true;
                },
                openView(data) {
                    this.view = data;
                    this.viewOpen = true;
                },
                fileUrl(event) {
                    const file = event.target.files[0];
                    return file ? URL.createObjectURL(file) : null;
                },

                // what each preview draws
                formSlide() {
                    return {
                        title: this.form.title, subtitle: this.form.subtitle, button_text: this.form.button_text,
                        bg_color: this.form.bg_color,
                        bg_image: this.bgPreview || (this.removeBg ? null : this.form.bg_image_url),
                        image: this.imagePreview || this.form.image_url,
                    };
                },
                viewSlide() {
                    const v = this.view;
                    return { title: v.title, subtitle: v.subtitle, button_text: v.button_text, bg_color: v.bg_color, bg_image: v.bg_image_url, image: v.image_url };
                },

                validColor(hex) {
                    return /^#[0-9a-f]{6}$/i.test(hex || '') ? hex : null;
                },
                slideStyle(s) {
                    const color = this.validColor(s.bg_color);
                    return (color ? `background-color:${color};` : '') + (s.bg_image ? `background-image:url('${s.bg_image}');` : '');
                },
                // same rule as the website: white text on a background image or a dark color
                lightText(s) {
                    if (s.bg_image) return true;
                    const hex = this.validColor(s.bg_color);
                    if (! hex) return false;
                    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
                    return (0.299 * r + 0.587 * g + 0.114 * b) < 150;
                },
            };
        }
    </script>
</x-admin.layout>
