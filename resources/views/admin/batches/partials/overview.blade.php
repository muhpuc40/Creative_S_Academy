@php
    $held = $batch->classes->where('status', \App\Enums\ClassStatus::Held)->count();
    // live classes only: a recorded video is not a class anyone attends
    $nextClass = $batch->classes
        ->filter(fn ($c) => $c->item?->item_type === \App\Enums\ItemType::Live)
        ->where('status', \App\Enums\ClassStatus::Scheduled)
        ->filter(fn ($c) => $c->class_start_time->isFuture())
        ->sortBy('class_start_time')->first();
@endphp
<div class="grid gap-6 lg:grid-cols-3">
    <x-admin.card class="lg:col-span-2">
        <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-semibold text-slate-900">Batch information</h3></div>
        <div class="px-5 py-2">
            <x-admin.info-list :items="[
                'Course' => $batch->course?->title,
                'Code' => $batch->code,
                'Main instructor' => $batch->instructor?->name,
                'Start date' => $batch->start_date->format('d M Y'),
                'Approx. end date' => $batch->approx_end_date?->format('d M Y'),
                'Enroll deadline' => $batch->enroll_deadline?->format('d M Y'),
                'Extended deadline' => $batch->extended_enroll_deadline?->format('d M Y'),
                'Seat limit' => $batch->seat_limit ?? 'Unlimited',
                'Enrollment open today' => $batch->isEnrollmentOpen(),
                'Active' => (bool) $batch->is_active,
            ]" />
        </div>
    </x-admin.card>

    <div class="space-y-6">
        <x-admin.card>
            <div class="grid grid-cols-3 divide-x divide-slate-100 text-center">
                <div class="px-3 py-5">
                    <p class="text-2xl font-semibold text-slate-900">{{ $held }}<span class="text-base font-normal text-slate-400">/{{ $batch->classes->count() }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">Classes held</p>
                </div>
                <div class="px-3 py-5">
                    <p class="text-2xl font-semibold text-slate-900">{{ $batch->exams->count() }}</p>
                    <p class="mt-1 text-xs text-slate-500">Exams</p>
                </div>
                <div class="px-3 py-5">
                    <p class="text-2xl font-semibold text-slate-900">{{ $batch->assignments->count() }}</p>
                    <p class="mt-1 text-xs text-slate-500">Assignments</p>
                </div>
            </div>
        </x-admin.card>

        <x-admin.card>
            <div class="px-5 py-4">
                <h3 class="font-semibold text-slate-900">Next live class</h3>
                @if ($nextClass)
                    <p class="mt-2 text-sm font-medium text-slate-900">{{ $nextClass->item?->module?->title }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ $nextClass->class_start_time->format('D, d M Y · h:i A') }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $nextClass->class_start_time->diffForHumans() }}</p>
                @else
                    <p class="mt-2 text-sm text-slate-500">No upcoming live class. <button type="button" class="text-indigo-600 underline" @click="go('classes')">Add one</button>.</p>
                @endif
            </div>
        </x-admin.card>
    </div>
</div>
