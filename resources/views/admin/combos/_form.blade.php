{{-- Shared by create and edit. Needs: $combo, $courses (id, title, currentPrice loaded), $selected (ids already in the
combo) --}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-admin.card title="Details">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-admin.input name="title" label="Title" x-model="form.title" placeholder="New Year Offer"
                        required />
                </div>
                <div class="sm:col-span-2">
                    <x-admin.input name="slug" label="Slug (link name)" :value="$combo->slug" placeholder="new-year-offer"
                        hint="Leave empty to make it from the title. The website link is /combos/slug." />
                </div>
                <div class="sm:col-span-2">
                    <x-admin.textarea name="description" label="Description (optional)" x-model="form.description"
                        rows="2" />
                </div>

                <x-admin.select name="price_type" label="Price type" x-model="form.price_type" required>
                    <option value="flat">Fixed price (taka)</option>
                    <option value="percent">Percent off the total</option>
                </x-admin.select>
                <div>
                    <x-admin.input name="price_value" type="number" step="0.01" min="0" label="Price value"
                        x-model="form.price_value" required />
                    <p class="mt-1.5 text-xs text-slate-500">
                        <span x-show="form.price_type === 'flat'">The combo's final price in taka.</span>
                        <span x-show="form.price_type === 'percent'">Percent (0-100) taken off the sum of the course
                            prices.</span>
                    </p>
                </div>

                <x-admin.input name="starts_at" type="date" label="Starts (optional)" x-model="form.starts_at"
                    hint="Empty = starts right away." />
                <x-admin.input name="ends_at" type="date" label="Deadline" x-model="form.ends_at" required />

                <div class="sm:col-span-2">
                    <x-admin.checkbox name="is_active" label="Active (an inactive combo is hidden from students)"
                        x-model="form.is_active" />
                </div>
            </div>
        </x-admin.card>

        <x-admin.card title="Courses in this combo">
            <p class="mb-3 text-xs text-slate-500">
                Pick at least 2 published, priced courses. The combo price is always calculated from their current
                price,
                so changing a course's price later changes this combo's price too.
            </p>
            @error('course_ids')
                <p class="mb-3 text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto rounded-lg ring-1 ring-slate-200">
                <template x-for="course in courses" :key="course.id">
                    <label
                        class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-sm hover:bg-slate-50">
                        <span class="flex items-center gap-3">
                            <input type="checkbox" name="course_ids[]" :value="course.id"
                                x-model.number="form.course_ids"
                                class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                            <span x-text="course.title"></span>
                        </span>
                        <span class="whitespace-nowrap text-slate-500"
                            x-text="'Tk ' + course.price.toLocaleString()"></span>
                    </label>
                </template>
                <template x-if="courses.length === 0">
                    <p class="px-4 py-6 text-center text-sm text-slate-500">No published, priced course is available
                        yet.</p>
                </template>
            </div>
        </x-admin.card>
    </div>

    <div>
        <x-admin.card title="Price preview" class="sticky top-18">
            <div x-data="{ thumbnailPreview: @js($combo->thumbnail_url) }">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between text-slate-600">
                    <dt>Courses picked</dt>
                    <dd x-text="selectedCourses.length"></dd>
                </div>
                <div class="flex justify-between text-slate-600">
                    <dt>Sum of current prices</dt>
                    <dd x-text="'Tk ' + sum.toLocaleString()"></dd>
                </div>
                <div class="flex justify-between text-emerald-600">
                    <dt>Discount</dt>
                    <dd x-text="'- Tk ' + discount.toLocaleString()"></dd>
                </div>
                <div class="flex justify-between border-t border-slate-100 pt-2 font-semibold text-slate-900">
                    <dt>Student pays</dt>
                    <dd x-text="'Tk ' + final.toLocaleString()"></dd>
                </div>
            </dl>

            <div class="mt-5 border-t border-slate-100 pt-4">
                <label for="thumbnail" class="block text-sm font-medium text-slate-700">Thumbnail (optional)</label>
                <input id="thumbnail" name="thumbnail" type="file" accept="image/*"
                    @change="thumbnailPreview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : thumbnailPreview"
                    class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                @error('thumbnail')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <template x-if="thumbnailPreview">
                    <img :src="thumbnailPreview" alt="Combo thumbnail preview" class="mt-3 h-28 w-full rounded-lg object-cover ring-1 ring-slate-200">
                </template>
            </div>

            <p class="mt-3 text-xs text-amber-600"
                x-show="form.price_type === 'flat' && sum > 0 && Number(form.price_value || 0) > sum">
                The combo price is higher than the courses' total, so the full price
                (Tk <span x-text="sum.toLocaleString()"></span>) will be used instead — a combo never costs more than
                buying the courses separately.
            </p>

            <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
                <x-admin.button :href="route('admin.combos.index')" variant="secondary">Cancel</x-admin.button>
                <x-admin.button type="submit">Save</x-admin.button>
            </div>
            </div>
        </x-admin.card>
    </div>
</div>