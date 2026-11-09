{{-- Class schedule. One popup for add / edit. The live link fields show only for a LIVE item. --}}
@php
    $classItems = collect($itemOptions['live'] ?? [])->map(fn ($i) => $i + ['type' => 'live'])
        ->concat(collect($itemOptions['recorded'] ?? [])->map(fn ($i) => $i + ['type' => 'recorded']));
    $empty = ['course_module_items_id' => '', 'class_start_time' => '', 'duration_minutes' => 90,
              'instructor_id' => (string) ($batch->instructor_id ?? ''), 'status' => 'scheduled',
              'platform' => 'meet', 'meeting_url' => '', 'recording_content_id' => ''];
@endphp

<div x-data="{
        open: false, mode: 'add', action: '', form: {},
        types: {{ \Illuminate\Support\Js::from($itemTypes) }},
        show(mode, action, form) { this.mode = mode; this.action = action; this.form = form; this.meetError = ''; this.open = true; },
        get isLive() { return this.types[this.form.course_module_items_id] === 'live'; },
        meetBusy: false, meetError: '',
        async generateMeet() {
            this.meetBusy = true; this.meetError = '';
            try {
                const res = await fetch('{{ route('admin.google-meet.link') }}', { method: 'POST', headers: {
                    'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.meeting_url) { this.form.meeting_url = data.meeting_url; this.form.platform = 'meet'; }
                else { this.meetError = data.message || 'Could not create the Google Meet link. Please try again.'; }
            } catch (e) { this.meetError = 'Could not reach the server. Please try again.'; }
            this.meetBusy = false;
        },
     }">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500">Each row is one class. For a live class add the meeting link; mark it as held after the class.</p>
        <x-admin.button type="button"
            @click="show('add', '{{ route('admin.batch-classes.store', $batch) }}', {{ \Illuminate\Support\Js::from($empty) }})">
            <x-admin.icon name="plus" class="size-4" /> Add class
        </x-admin.button>
    </div>

    <x-admin.card>
        @if ($batch->classes->isEmpty())
            <x-admin.empty-state icon="clock" title="No classes yet"
                :message="$classItems->isEmpty() ? 'This course has no live or recorded class items yet. Add them on the course curriculum first.' : 'Add the first class of this batch.'" />
        @else
            <x-admin.table>
                <x-slot:head><th>#</th><th>When</th><th>Class</th><th>Instructor</th><th>Live link</th><th>Status</th><th class="text-right">Actions</th></x-slot:head>
                @foreach ($batch->classes as $class)
                    @php($live = $class->liveInfo)
                    <tr>
                        <td class="text-slate-400">{{ $loop->iteration }}</td>
                        <td class="whitespace-nowrap">
                            <p class="font-medium text-slate-900">{{ $class->class_start_time->format('D, d M Y') }}</p>
                            <p class="text-xs text-slate-500">{{ $class->class_start_time->format('h:i A') }} – {{ $class->endsAt()->format('h:i A') }}</p>
                        </td>
                        <td>
                            <p class="font-medium text-slate-900">{{ $class->item?->module?->title }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $class->item?->content?->name ?? \Illuminate\Support\Str::limit((string) $class->item?->objective, 40) ?: '—' }}
                                · <span class="capitalize">{{ $class->item?->item_type->value }}</span>
                            </p>
                        </td>
                        <td>{{ $class->instructor?->name ?? '—' }}</td>
                        <td>
                            @if ($live)
                                <x-admin.badge color="indigo" class="capitalize">{{ $live->platform->value }}</x-admin.badge>
                                @if ($live->meeting_url)
                                    <a href="{{ $live->meeting_url }}" target="_blank" rel="noopener" class="ml-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:underline">
                                        Open <x-admin.icon name="external-link" class="size-3" /></a>
                                @else
                                    <span class="ml-1 text-xs text-amber-600">No link yet</span>
                                @endif
                                @if ($live->recording)
                                    <p class="mt-1 text-xs text-slate-500">Recording: {{ $live->recording->name }}</p>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td><x-admin.state-badge :status="$class->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            @if ($class->status !== \App\Enums\ClassStatus::Cancelled)
                                <form method="POST" action="{{ route('admin.batch-classes.held', $class) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <x-admin.button type="submit" variant="secondary" size="sm">
                                        {{ $class->status === \App\Enums\ClassStatus::Held ? 'Undo held' : 'Mark held' }}
                                    </x-admin.button>
                                </form>
                            @endif
                            <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit" aria-label="Edit class"
                                @click="show('edit', '{{ route('admin.batch-classes.update', $class) }}', {{ \Illuminate\Support\Js::from([
                                    'course_module_items_id' => (string) $class->course_module_items_id,
                                    'class_start_time' => $class->class_start_time->format('Y-m-d\TH:i'),
                                    'duration_minutes' => $class->duration_minutes,
                                    'instructor_id' => (string) ($class->instructor_id ?? ''),
                                    'status' => $class->status->value,
                                    'platform' => $live?->platform->value ?? 'meet',
                                    'meeting_url' => $live?->meeting_url ?? '',
                                    'recording_content_id' => (string) ($live?->recording_content_id ?? ''),
                                ]) }})">
                                <x-admin.icon name="edit" class="size-4" />
                            </button>
                            <x-admin.delete-form :action="route('admin.batch-classes.destroy', $class)" confirm="Delete this class?" label="Delete class">
                                <x-admin.icon name="trash" class="size-4" />
                            </x-admin.delete-form>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.card>

    <x-admin.dialog show="open" close="open = false" title="mode === 'add' ? 'Add class' : 'Edit class'" max-width="max-w-4xl">
        <form method="POST" :action="action" class="space-y-4">
            @csrf
            <input type="hidden" name="_modal" value="1">
            <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">

            {{-- left: class details; right: live class link (only for a live item) --}}
            <div class="grid gap-6" :class="isLive && 'md:grid-cols-2'">
                <div class="space-y-4">
                    <x-admin.select id="class_item" name="course_module_items_id" label="Class (module item)" x-model="form.course_module_items_id" required>
                        <option value="">Choose a class...</option>
                        @if (! empty($itemOptions['live']))
                            <optgroup label="Live classes">
                                @foreach ($itemOptions['live'] as $item) <option value="{{ $item['id'] }}">{{ $item['label'] }}</option> @endforeach
                            </optgroup>
                        @endif
                        @if (! empty($itemOptions['recorded']))
                            <optgroup label="Recorded classes">
                                @foreach ($itemOptions['recorded'] as $item) <option value="{{ $item['id'] }}">{{ $item['label'] }}</option> @endforeach
                            </optgroup>
                        @endif
                    </x-admin.select>

                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.input id="class_start" name="class_start_time" type="datetime-local" label="Start" x-model="form.class_start_time" required />
                        <x-admin.input id="class_duration" name="duration_minutes" type="number" min="1" max="600" label="Minutes" x-model="form.duration_minutes" required />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.select id="class_instructor" name="instructor_id" label="Instructor" x-model="form.instructor_id">
                            <option value="">Batch instructor</option>
                            @foreach ($instructors as $instructor) <option value="{{ $instructor->id }}">{{ $instructor->name }}</option> @endforeach
                        </x-admin.select>
                        <x-admin.select id="class_status" name="status" label="Status" x-model="form.status" required>
                            @foreach (\App\Enums\ClassStatus::cases() as $status) <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option> @endforeach
                        </x-admin.select>
                    </div>
                </div>

                <div x-show="isLive" x-cloak class="space-y-4 self-start rounded-lg bg-slate-50 p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Live class link</p>
                    <x-admin.select id="class_platform" name="platform" label="Platform" x-model="form.platform" ::disabled="! isLive">
                        @foreach (\App\Enums\LivePlatform::cases() as $platform) <option value="{{ $platform->value }}">{{ ucfirst($platform->value) }}</option> @endforeach
                    </x-admin.select>
                    <div class="relative">
                        <x-admin.input id="class_url" name="meeting_url" type="url" label="Meeting link" x-model="form.meeting_url" placeholder="https://" ::disabled="! isLive"
                            hint="Students never see this link. They join through a tracked link." />
                        {{-- beside the label: a new Google Meet link from the connected Google account (System > Google Meet) --}}
                        @if ($meetReady)
                            <button type="button" @click="generateMeet()" :disabled="meetBusy"
                                class="absolute right-0 top-0 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline disabled:opacity-50">
                                <x-admin.icon name="video" class="size-3.5" />
                                <span x-text="meetBusy ? 'Generating...' : 'Generate Google Meet link'"></span>
                            </button>
                        @else
                            <a href="{{ route('admin.google-meet.index') }}" class="absolute right-0 top-0 text-xs font-medium text-slate-500 hover:text-indigo-600 hover:underline">Set up Google Meet</a>
                        @endif
                        <p x-show="meetError" x-text="meetError" x-cloak class="mt-1.5 text-xs text-red-600"></p>
                    </div>
                    <x-admin.select id="class_recording" name="recording_content_id" label="Recording (after the class)" x-model="form.recording_content_id" ::disabled="! isLive">
                        <option value="">Not yet</option>
                        @foreach ($contents as $content) <option value="{{ $content->id }}">{{ $content->name }}</option> @endforeach
                    </x-admin.select>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                <x-admin.button type="submit">Save class</x-admin.button>
            </div>
        </form>
    </x-admin.dialog>
</div>
