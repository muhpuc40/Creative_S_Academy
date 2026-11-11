{{-- Assignments of the batch. The assignment item comes from the course curriculum. --}}
@php
    $assignmentItems = $itemOptions['assignment'] ?? [];
    $empty = [
        'course_module_items_id' => '',
        'title' => '',
        'instruction' => '',
        'assigned_at' => now()->format('Y-m-d\TH:i'),
        'due_at' => '',
        'total_marks' => 20,
        'allow_late' => false,
        'status' => 'draft'
    ];
@endphp

<div x-data="{
        open: false, mode: 'add', action: '', form: {}, uploading: false,
        show(mode, action, form) { this.mode = mode; this.action = action; this.form = form; this.$dispatch('uploader-reset'); this.open = true; },
     }" @uploader-busy.window="uploading = $event.detail">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500">Students see an assignment once it is Published. Closed assignments take no
            more submissions.</p>
        <x-admin.button type="button"
            @click="show('add', '{{ route('admin.batch-assignments.store', $batch) }}', {{ \Illuminate\Support\Js::from($empty) }})">
            <x-admin.icon name="plus" class="size-4" /> Add assignment
        </x-admin.button>
    </div>

    <x-admin.card>
        @if ($batch->assignments->isEmpty())
            <x-admin.empty-state icon="file" title="No assignments yet" :message="$assignmentItems ? 'Add the first assignment of this batch.' : 'This course has no assignment items yet. Add them on the course curriculum first.'" />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th>Assignment</th>
                    <th>Assigned</th>
                    <th>Due</th>
                    <th>Marks</th>
                    <th>File</th>
                    <th>Late</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </x-slot:head>
                @foreach ($batch->assignments as $assignment)
                        <tr>
                            <td>
                                <p class="font-medium text-slate-900">{{ $assignment->title }}</p>
                                <p class="text-xs text-slate-500">{{ $assignment->item?->module?->title }}</p>
                            </td>
                            <td class="whitespace-nowrap">{{ $assignment->assigned_at->format('d M Y, h:i A') }}</td>
                            <td class="whitespace-nowrap">{{ $assignment->due_at->format('d M Y, h:i A') }}</td>
                            <td>{{ $assignment->total_marks }}</td>
                            <td>
                                @if ($assignment->assignment_file)
                                    <a href="{{ $assignment->assignment_file_url }}" target="_blank" rel="noopener"
                                        class="inline-flex items-center gap-1 text-indigo-600 hover:underline">
                                        View <x-admin.icon name="external-link" class="size-3.5" />
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td><x-admin.badge :color="$assignment->allow_late ? 'amber' : 'gray'">{{ $assignment->allow_late ? 'Allowed' : 'No' }}</x-admin.badge></td>
                            <td><x-admin.state-badge :status="$assignment->status" /></td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit"
                                    aria-label="Edit assignment" @click="show('edit', '{{ route('admin.batch-assignments.update', $assignment) }}', {{ \Illuminate\Support\Js::from([
                        'course_module_items_id' => (string) $assignment->course_module_items_id,
                        'title' => $assignment->title,
                        'instruction' => $assignment->instruction ?? '',
                        'assigned_at' => $assignment->assigned_at->format('Y-m-d\TH:i'),
                        'due_at' => $assignment->due_at->format('Y-m-d\TH:i'),
                        'total_marks' => $assignment->total_marks,
                        'allow_late' => (bool) $assignment->allow_late,
                        'status' => $assignment->status->value,
                    ]) }})">
                                    <x-admin.icon name="edit" class="size-4" />
                                </button>
                                <x-admin.delete-form :action="route('admin.batch-assignments.destroy', $assignment)"
                                    confirm="Delete this assignment?" label="Delete assignment">
                                    <x-admin.icon name="trash" class="size-4" />
                                </x-admin.delete-form>
                            </td>
                        </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.card>

    <x-admin.dialog show="open" close="open = false" title="mode === 'add' ? 'Add assignment' : 'Edit assignment'"
        max-width="max-w-xl">
        <form method="POST" :action="action" class="space-y-4">
            @csrf
            <input type="hidden" name="_modal" value="1">
            <input type="hidden" name="_method" value="PUT" :disabled="mode === 'add'">

            <x-admin.select id="asg_item" name="course_module_items_id" label="Assignment item of the course"
                x-model="form.course_module_items_id" required>
                <option value="">Choose an assignment...</option>
                @foreach ($assignmentItems as $item)
                <option value="{{ $item['id'] }}">{{ $item['label'] }}</option> @endforeach
            </x-admin.select>
            <x-admin.input id="asg_title" name="title" label="Title" x-model="form.title"
                placeholder="Build a landing page" required />
            <x-admin.textarea id="asg_instruction" name="instruction" label="Instruction" x-model="form.instruction"
                rows="4" />

            <div class="grid grid-cols-2 gap-3">
                <x-admin.input id="asg_assigned" name="assigned_at" type="datetime-local" label="Assigned at"
                    x-model="form.assigned_at" required />
                <x-admin.input id="asg_due" name="due_at" type="datetime-local" label="Due at" x-model="form.due_at"
                    required />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-admin.input id="asg_marks" name="total_marks" type="number" min="1" label="Total marks"
                    x-model="form.total_marks" required />
                <x-admin.select id="asg_status" name="status" label="Status" x-model="form.status" required>
                    @foreach (\App\Enums\AssignmentStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option> @endforeach
                </x-admin.select>
            </div>
            @php($limits = \App\Models\FileType::limits())
            @include('admin.partials.chunkit-uploader', [
                'id' => 'asg_file',
                'label' => 'Assignment file',
                'accept' => implode(',', array_map(fn ($ext) => ".{$ext}", array_keys($limits))),
                'hint' => 'Formats and sizes from System > File types. Edit: leave empty to keep the current file.',
                'limits' => $limits,
            ])
            <x-admin.checkbox id="asg_late" name="allow_late" label="Accept late submissions after the due time"
                x-model="form.allow_late" />

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <x-admin.button type="button" variant="secondary" @click="open = false">Cancel</x-admin.button>
                <x-admin.button type="submit" ::disabled="uploading">
                    <span x-text="uploading ? 'Wait for the upload…' : 'Save assignment'">Save assignment</span>
                </x-admin.button>
            </div>
        </form>
    </x-admin.dialog>
</div>