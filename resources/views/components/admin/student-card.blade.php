{{--
  <x-admin.student-card :student="$order->student" />
  Card with the student's avatar on the left (initials when there is no photo) and name / email / phone on the right.
--}}
@props(['student', 'title' => 'Student'])
@php
    $initials = collect(preg_split('/\s+/', trim((string) $student?->name)))->filter()->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('') ?: '?';
@endphp
<x-admin.card :title="$title" {{ $attributes }}>
    <div class="flex items-center gap-4">
        <div class="shrink-0">
            @if ($student?->avatarUrl())
                <img src="{{ $student->avatarUrl() }}" alt="" class="size-14 rounded-full object-cover ring-2 ring-white shadow-sm">
            @else
                <span class="flex size-14 items-center justify-center rounded-full bg-indigo-50 text-lg font-semibold text-indigo-600">{{ $initials }}</span>
            @endif
        </div>
        <div class="min-w-0 space-y-0.5">
            <p class="truncate font-medium text-slate-900">{{ $student?->name ?? 'Student removed' }}</p>
            @if ($student?->email)
                <p class="truncate text-sm text-slate-500">{{ $student->email }}</p>
            @endif
            @if ($student?->phone)
                <p class="text-sm text-slate-500">{{ $student->phone }}</p>
            @endif
        </div>
    </div>
</x-admin.card>
