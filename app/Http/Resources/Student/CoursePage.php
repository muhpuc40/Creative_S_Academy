<?php

namespace App\Http\Resources\Student;

use App\Enums\ItemType;
use App\Models\BatchAssignment;
use App\Models\BatchClassSchedule;
use App\Models\BatchExam;
use App\Models\ClassAttendance;
use App\Models\CourseCurriculum;
use App\Models\CourseModule;
use App\Models\CourseModuleItem;
use App\Models\Enrollment;
use App\Models\EnrollmentResult;
use App\Services\Enrollment\AccessService;
use App\Services\Enrollment\ClassJoinService;
use App\Services\Enrollment\CourseMap;
use App\Services\Enrollment\RenewalService;
use App\Services\Enrollment\SubmissionService;
use App\Support\Media;
use Illuminate\Support\Collection;

/**
 * The data of an enrolled student's course pages, all read from one CourseMap. Never contains a
 * video, file or meeting link: those come only from the action endpoints (play, resource, join,
 * recording, assignment, exam), which run the full checks each time.
 *
 * Every item, in every list:
 *   id, type, title, done, excused, can_open, reason (why it's locked; null when open), available_at
 * The module page adds a block for its type (class / exam / assignment / video / resource).
 */
class CoursePage
{
    private const DEADLINE_DAYS = 14;

    public function __construct(
        private CourseMap $map,
        private ClassJoinService $joins,
        private SubmissionService $submissions,
    ) {}

    public static function for(Enrollment $enrollment): self
    {
        return new self(CourseMap::for($enrollment), app(ClassJoinService::class), app(SubmissionService::class));
    }

    /** GET learn/{slug}: the course home. */
    public function overview(): array
    {
        $enrollment = $this->map->enrollment->loadMissing(['course.learningOutcomes', 'batch.instructor', 'orderItem.installments']);
        $course = $enrollment->course;
        $batch = $enrollment->batch;
        $items = $this->map->items();
        $this->map->loadNames();

        $continue = $items->first(fn (CourseModuleItem $i) => !$this->map->isDone($i->id) && $this->actionable($i));
        $installments = $enrollment->orderItem?->installments ?? collect();

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'thumbnail' => $course->thumbnail_url,
                'level' => $course->level,
            ],
            'batch' => [
                'id' => $batch?->id,
                'name' => $batch?->name,
                'code' => $batch?->code,
                'start_date' => $batch?->start_date?->toDateString(),
                'approx_end_date' => $batch?->approx_end_date?->toDateString(),
                'status' => $batch?->status?->value,
                'instructor' => $batch?->instructor ? [
                    'name' => $batch->instructor->name,
                    'designation' => $batch->instructor->designation,
                    'avatar' => Media::url($batch->instructor->avatar),
                ] : null,
            ],
            'enrollment' => [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'progress_percent' => (float) $enrollment->progress_percent,
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'access_expires_at' => $enrollment->access_expires_at?->toIso8601String(),
                'has_access' => $enrollment->hasContentAccess(),   // false = videos, recordings and resources are locked
            ],
            'renewal' => app(RenewalService::class)->summary($enrollment),
            'dues' => [
                'paid' => $installments->where('status', \App\Enums\InstallmentStatus::Paid)->count(),
                'total' => $installments->count(),
            ],
            'unlock' => $this->unlock(),
            'counts' => collect(ItemType::cases())->mapWithKeys(fn (ItemType $t) => [
                $t->value => $this->map->tally($items->where('item_type', $t)),
            ])->all(),
            'continue' => $continue ? $this->item($continue) + ['module' => $this->moduleRef($continue)] : null,
            'next_class' => $this->nextClass(),
            'deadlines' => $this->deadlines(),
            'stages' => $this->map->stages()->map(fn (CourseCurriculum $s) => $this->stage($s, withModules: false))->values()->all(),
            'learning_outcomes' => $course->learningOutcomes->pluck('title')->values()->all(),
        ];
    }

    /** GET learn/{slug}/outline: stages > modules > items, for the sidebar. */
    public function outline(): array
    {
        $this->map->loadNames();

        return [
            'progress_percent' => (float) $this->map->enrollment->progress_percent,
            'unlock' => $this->unlock(),
            'stages' => $this->map->stages()->map(fn (CourseCurriculum $s) => $this->stage($s, withModules: true))->values()->all(),
        ];
    }

    /** GET learn/{slug}/modules/{moduleId}: one module with each item's full details. Null = not in this course. */
    public function module(int $moduleId): ?array
    {
        $module = $this->map->module($moduleId);
        if (!$module) {
            return null;
        }
        $this->map->loadNames();
        $stage = $this->map->stageOf($module);

        return $this->moduleSummary($module) + [
            'stage' => ['id' => $stage->id, 'title' => $stage->title],
            'unlock' => $this->map->moduleOpen($module->id) ? null : $this->unlock(),
            'items' => $this->map->moduleItems($module->id)->map(fn (CourseModuleItem $i) => $this->itemDetail($i))->all(),
        ];
    }

    /** GET learn/{slug}/schedule: the batch's dates for this course, upcoming (soonest first) and past (latest first). */
    public function schedule(): array
    {
        $this->map->loadNames();
        $events = collect();

        foreach ($this->map->items() as $item) {
            $base = ['item_id' => $item->id, 'title' => $this->map->title($item), 'module' => $this->moduleRef($item), 'done' => $this->map->isDone($item->id)];

            if (in_array($item->item_type, [ItemType::Live, ItemType::Recorded], true)) {
                foreach ($this->map->classesFor($item) as $class) {
                    $events->push(['type' => $item->item_type->value, 'class_id' => $class->id] + $base + [
                        'starts_at' => $class->class_start_time,
                        'ends_at' => $item->item_type === ItemType::Live ? $class->endsAt() : null,
                        'status' => $class->status->value,
                    ]);
                }
            } elseif ($item->item_type === ItemType::Exam && ($exam = $this->map->examFor($item))) {
                $events->push(['type' => 'exam', 'exam_id' => $exam->id] + $base + [
                    'starts_at' => $exam->startsAt(), 'ends_at' => $exam->endsAt(), 'status' => $exam->status->value,
                ]);
            } elseif ($item->item_type === ItemType::Assignment && ($work = $this->map->assignmentFor($item))) {
                $events->push(['type' => 'assignment', 'assignment_id' => $work->id] + $base + [
                    'starts_at' => $work->assigned_at, 'ends_at' => $work->due_at, 'status' => $work->status->value,
                ]);
            }
        }

        [$upcoming, $past] = $events->partition(fn (array $e) => now()->lte($e['ends_at'] ?? $e['starts_at']));
        $iso = fn (array $e) => ['starts_at' => $e['starts_at']->toIso8601String(), 'ends_at' => $e['ends_at']?->toIso8601String()] + $e;

        return [
            'upcoming' => $upcoming->sortBy(fn ($e) => $e['starts_at']->timestamp)->map($iso)->values()->all(),
            'past' => $past->sortByDesc(fn ($e) => $e['starts_at']->timestamp)->map($iso)->values()->all(),
        ];
    }

    /** GET learn/{slug}/results: every exam and assignment, my submission and marks. */
    public function results(): array
    {
        $this->map->loadNames();
        $rows = collect();

        foreach ($this->map->items() as $item) {
            $work = match ($item->item_type) {
                ItemType::Exam => $this->map->examFor($item),
                ItemType::Assignment => $this->map->assignmentFor($item),
                default => null,
            };
            if (!$work) {
                continue;
            }

            $mine = $this->map->resultFor($work);
            $visible = $this->marksVisible($work, $mine);
            $rows->push([
                'type' => $item->item_type->value,
                'item_id' => $item->id,
                $work instanceof BatchExam ? 'exam_id' : 'assignment_id' => $work->id,
                'title' => $this->map->title($item),
                'module' => $this->moduleRef($item),
                'total_marks' => $work->total_marks,
                // submitted | graded | absent (from the admin), missed (closed without one), not_submitted
                'status' => $mine?->status ?? ($this->closed($work) ? 'missed' : 'not_submitted'),
                'submitted_at' => $mine?->submitted_at?->toIso8601String(),
                'is_late' => (bool) $mine?->is_late,
                'result_published' => $work instanceof BatchExam ? $work->isResultPublished() : null,
                'marks_obtained' => $visible && $mine?->marks_obtained !== null ? (float) $mine->marks_obtained : null,
                'feedback' => $visible ? $mine?->feedback : null,
            ]);
        }

        $marked = $rows->whereNotNull('marks_obtained');
        $total = (int) $marked->sum('total_marks');

        return [
            'summary' => [
                'marks_obtained' => (float) $marked->sum('marks_obtained'),
                'total_marks' => $total,
                'percent' => $total > 0 ? round($marked->sum('marks_obtained') / $total * 100, 2) : null,
                'marked' => $marked->count(),
                'count' => $rows->count(),
            ],
            'items' => $rows->values()->all(),
        ];
    }

    /** GET learn/{slug}/attendance: live classes that have started, with my attendance. */
    public function attendance(): array
    {
        $this->map->loadNames();
        $rows = collect();

        foreach ($this->map->items()->where('item_type', ItemType::Live) as $item) {
            foreach ($this->map->classesFor($item) as $class) {
                if (now()->lt($class->class_start_time)) {
                    continue;   // not started yet
                }
                $mine = $this->map->attendanceFor($class);
                $rows->push([
                    'class_id' => $class->id,
                    'item_id' => $item->id,
                    'title' => $this->map->title($item),
                    'module' => $this->moduleRef($item),
                    'starts_at' => $class->class_start_time->toIso8601String(),
                    // present | late | excused | absent (ended, never joined) | ongoing
                    'status' => $mine?->status ?? (now()->gt($class->endsAt()) ? 'absent' : 'ongoing'),
                    'first_joined_at' => $mine?->first_joined_at?->toIso8601String(),
                ]);
            }
        }

        $count = fn (string $status) => $rows->where('status', $status)->count();
        $attended = $count(ClassAttendance::STATUS_PRESENT) + $count(ClassAttendance::STATUS_LATE);
        $counted = $attended + $count('absent');   // excused and ongoing classes don't count

        return [
            'summary' => [
                'present' => $count(ClassAttendance::STATUS_PRESENT),
                'late' => $count(ClassAttendance::STATUS_LATE),
                'excused' => $count(ClassAttendance::STATUS_EXCUSED),
                'absent' => $count('absent'),
                'percent' => $counted > 0 ? round($attended / $counted * 100, 2) : null,
            ],
            'classes' => $rows->sortByDesc('starts_at')->values()->all(),
        ];
    }

    /** GET learn/{slug}/resources: every resource of the course in one list. */
    public function resources(): array
    {
        $this->map->loadNames();

        return $this->map->items()->where('item_type', ItemType::Resources)
            ->map(fn (CourseModuleItem $i) => $this->item($i) + ['module' => $this->moduleRef($i)])
            ->values()->all();
    }

    // ---- pieces -----------------------------------------------------------------------------

    /** An item, light (lists and the sidebar). */
    private function item(CourseModuleItem $item): array
    {
        $reason = $this->map->reason($item->id);

        return [
            'id' => $item->id,
            'type' => $item->item_type->value,
            'title' => $this->map->title($item),
            'done' => $this->map->isDone($item->id),
            'excused' => $this->map->isExcused($item->id),
            'can_open' => $reason === AccessService::OK,
            'reason' => $reason === AccessService::OK ? null : $reason,
            'available_at' => $item->item_type === ItemType::Recorded ? $this->map->releaseAt($item)?->toIso8601String() : null,
        ];
    }

    /** An item with the block for its type (module page). */
    private function itemDetail(CourseModuleItem $item): array
    {
        $row = $this->item($item);

        return $row + match ($item->item_type) {
            ItemType::Recorded => ['video' => [
                'name' => $item->content?->name,
                'thumbnail' => $item->content?->thumbnail_url,
                'has_video' => (bool) $item->content && ($item->content->hasStoredFile() || $item->content->file_url),
                'resume_at' => $this->map->resumeAt($item->id),
            ]],
            ItemType::Resources => ['resource' => ['name' => $item->resource?->name]],
            ItemType::Live => ['class' => ($class = $this->map->classFor($item)) ? $this->classBlock($item, $class) : null],
            ItemType::Exam => ['exam' => ($exam = $this->map->examFor($item)) ? $this->examBlock($item, $exam) : null],
            ItemType::Assignment => ['assignment' => ($work = $this->map->assignmentFor($item)) ? $this->assignmentBlock($item, $work) : null],
        };
    }

    private function classBlock(CourseModuleItem $item, BatchClassSchedule $class): array
    {
        $access = $this->map->reason($item->id);
        $timing = $this->joins->timing($class);
        $reason = $access !== AccessService::OK ? $access : $timing;
        $mine = $this->map->attendanceFor($class);

        return [
            'id' => $class->id,
            'starts_at' => $class->class_start_time->toIso8601String(),
            'ends_at' => $class->endsAt()->toIso8601String(),
            'opens_at' => $this->joins->opensAt($class)->toIso8601String(),
            'status' => $class->status->value,
            'platform' => $class->liveInfo?->platform?->value,
            'ended' => now()->gt($class->endsAt()),
            'can_join_now' => $reason === ClassJoinService::OK,
            'join_reason' => $reason === ClassJoinService::OK ? null : $reason,   // too_early | ended | no_link | an access reason
            'my_attendance' => $mine ? ['status' => $mine->status, 'first_joined_at' => $mine->first_joined_at?->toIso8601String()] : null,
            'has_recording' => (bool) $class->liveInfo?->recording_content_id,
            'resume_at' => $this->map->resumeAt($item->id),
        ];
    }

    private function examBlock(CourseModuleItem $item, BatchExam $exam): array
    {
        $mine = $this->map->resultFor($exam);
        $reason = $this->submitReason($item, $exam, $mine);

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'starts_at' => $exam->startsAt()->toIso8601String(),
            'ends_at' => $exam->endsAt()->toIso8601String(),
            'closes_at' => $this->submissions->examClosesAt($exam)->toIso8601String(),
            'duration_minutes' => $exam->duration_minutes,
            'total_marks' => $exam->total_marks,
            'status' => $exam->status->value,
            'can_submit' => $reason === SubmissionService::OK,
            'submit_reason' => $reason === SubmissionService::OK ? null : $reason,
            'result_published' => $exam->isResultPublished(),
            'my_submission' => $mine ? $this->submission($mine, $this->marksVisible($exam, $mine)) : null,
        ];
    }

    private function assignmentBlock(CourseModuleItem $item, BatchAssignment $work): array
    {
        $mine = $this->map->resultFor($work);
        $reason = $this->submitReason($item, $work, $mine);

        return [
            'id' => $work->id,
            'title' => $work->title,
            'assigned_at' => $work->assigned_at->toIso8601String(),
            'due_at' => $work->due_at->toIso8601String(),
            'allow_late' => $work->allow_late,
            'total_marks' => $work->total_marks,
            'status' => $work->status->value,
            'can_submit' => $reason === SubmissionService::OK,
            'submit_reason' => $reason === SubmissionService::OK ? null : $reason,
            'my_submission' => $mine ? $this->submission($mine, $this->marksVisible($work, $mine)) : null,
        ];
    }

    /** Same fields as the assignment / exam endpoints, without the file link. */
    private function submission(EnrollmentResult $result, bool $marksVisible): array
    {
        return [
            'file_name' => $result->file_name,
            'submitted_at' => $result->submitted_at?->toIso8601String(),
            'is_late' => $result->is_late,
            'status' => $result->status,
            'marks_obtained' => $marksVisible && $result->marks_obtained !== null ? (float) $result->marks_obtained : null,
            'total_marks' => $marksVisible ? $result->total_marks : null,
            'feedback' => $marksVisible ? $result->feedback : null,
        ];
    }

    private function submitReason(CourseModuleItem $item, BatchAssignment|BatchExam $work, ?EnrollmentResult $mine): string
    {
        $access = $this->map->reason($item->id);

        return $access === AccessService::OK ? $this->submissions->timing($work, $mine) : $access;
    }

    /** Assignment marks show once graded; exam marks once the result is published. */
    private function marksVisible(BatchAssignment|BatchExam $work, ?EnrollmentResult $mine): bool
    {
        return $work instanceof BatchExam
            ? $work->isResultPublished()
            : $mine?->status === EnrollmentResult::STATUS_GRADED;
    }

    /** Past the time it accepted submissions. */
    private function closed(BatchAssignment|BatchExam $work): bool
    {
        return $work instanceof BatchExam
            ? now()->gt($this->submissions->examClosesAt($work))
            : $this->submissions->timing($work, null) === SubmissionService::CLOSED;
    }

    private function stage(CourseCurriculum $stage, bool $withModules): array
    {
        $modules = $this->map->modules($stage->id);
        $tally = $this->map->tally($modules->flatMap(fn (CourseModule $m) => $this->map->moduleItems($m->id)));

        return [
            'id' => $stage->id,
            'title' => $stage->title,
            'description' => $stage->description,
            'duration_days' => $stage->duration,
            'done' => $tally['done'],
            'total' => $tally['total'],
            'percent' => $this->percent($tally),
            'can_open' => $modules->contains(fn (CourseModule $m) => $this->map->moduleOpen($m->id)),
        ] + ($withModules ? [
            'modules' => $modules->map(fn (CourseModule $m) => $this->moduleSummary($m) + [
                'items' => $this->map->moduleItems($m->id)->map(fn (CourseModuleItem $i) => $this->item($i))->all(),
            ])->all(),
        ] : []);
    }

    private function moduleSummary(CourseModule $module): array
    {
        $tally = $this->map->tally($this->map->moduleItems($module->id));
        $open = $this->map->moduleOpen($module->id);

        return [
            'id' => $module->id,
            'title' => $module->title,
            'week' => $module->week,
            'objective' => $module->objective,
            'done' => $tally['done'],
            'total' => $tally['total'],
            'percent' => $this->percent($tally),
            'can_open' => $open,
            'reason' => $open ? null : AccessService::PAYMENT_LOCKED,
        ];
    }

    /** What unlocks the locked modules: the next unpaid installment (it may be waiting for verification). */
    private function unlock(): ?array
    {
        $from = $this->map->lockedFromModule();
        $due = $this->map->nextDue();
        if (!$from || !$due) {
            return null;
        }

        return [
            'from_module' => ['id' => $from->id, 'title' => $from->title],
            'installment' => ['id' => $due->id, 'title' => $due->title, 'amount' => (float) $due->amount, 'status' => $due->status->value],
        ];
    }

    private function moduleRef(CourseModuleItem $item): array
    {
        $module = $this->map->moduleOf($item);

        return ['id' => $module?->id, 'title' => $module?->title];
    }

    /** Something the student can do right now (for "Continue learning"). */
    private function actionable(CourseModuleItem $item): bool
    {
        if (!$this->map->canOpen($item->id)) {
            return false;
        }

        return match ($item->item_type) {
            ItemType::Recorded, ItemType::Resources => true,
            ItemType::Live => ($class = $this->map->classFor($item)) !== null
                && ($this->joins->timing($class) === ClassJoinService::OK
                    || (now()->gt($class->endsAt()) && $class->liveInfo?->recording_content_id && $this->map->enrollment->hasContentAccess())),
            ItemType::Exam => ($exam = $this->map->examFor($item)) !== null
                && $this->submissions->timing($exam, $this->map->resultFor($exam)) === SubmissionService::OK,
            ItemType::Assignment => ($work = $this->map->assignmentFor($item)) !== null
                && $this->submissions->timing($work, $this->map->resultFor($work)) === SubmissionService::OK,
        };
    }

    private function nextClass(): ?array
    {
        $next = $this->map->items()->where('item_type', ItemType::Live)
            ->flatMap(fn (CourseModuleItem $i) => $this->map->classesFor($i)->map(fn (BatchClassSchedule $c) => [$i, $c]))
            ->filter(fn (array $pair) => now()->lte($pair[1]->endsAt()))
            ->sortBy(fn (array $pair) => $pair[1]->class_start_time->timestamp)
            ->first();

        if (!$next) {
            return null;
        }
        [$item, $class] = $next;

        return ['item_id' => $item->id, 'title' => $this->map->title($item), 'module' => $this->moduleRef($item)] + $this->classBlock($item, $class);
    }

    /** Assignments due and exams starting in the next 14 days that I haven't submitted yet. */
    private function deadlines(): array
    {
        $until = now()->addDays(self::DEADLINE_DAYS);
        $rows = collect();

        foreach ($this->map->items() as $item) {
            if ($this->map->isDone($item->id)) {
                continue;
            }
            if ($item->item_type === ItemType::Assignment && ($work = $this->map->assignmentFor($item))
                && $work->due_at->between(now(), $until)) {
                $rows->push(['type' => 'assignment', 'assignment_id' => $work->id, 'item_id' => $item->id,
                    'title' => $this->map->title($item), 'at' => $work->due_at, 'can_open' => $this->map->canOpen($item->id)]);
            }
            if ($item->item_type === ItemType::Exam && ($exam = $this->map->examFor($item))
                && $this->submissions->examClosesAt($exam)->isFuture() && $exam->startsAt()->lte($until)) {
                $rows->push(['type' => 'exam', 'exam_id' => $exam->id, 'item_id' => $item->id,
                    'title' => $this->map->title($item), 'at' => $exam->startsAt(), 'can_open' => $this->map->canOpen($item->id)]);
            }
        }

        return $rows->sortBy(fn ($r) => $r['at']->timestamp)->take(5)
            ->map(fn ($r) => ['at' => $r['at']->toIso8601String()] + $r)->values()->all();
    }

    /** @param array{done: int, total: int} $tally */
    private function percent(array $tally): float
    {
        return $tally['total'] > 0 ? round($tally['done'] / $tally['total'] * 100, 2) : 0.0;
    }
}
