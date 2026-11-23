<?php

namespace App\Services\Enrollment;

use App\Enums\AssignmentStatus;
use App\Enums\ClassStatus;
use App\Enums\ExamStatus;
use App\Enums\InstallmentStatus;
use App\Enums\ItemType;
use App\Models\BatchAssignment;
use App\Models\BatchClassSchedule;
use App\Models\BatchExam;
use App\Models\ClassAttendance;
use App\Models\CourseCurriculum;
use App\Models\CourseModule;
use App\Models\CourseModuleItem;
use App\Models\Enrollment;
use App\Models\EnrollmentProgress;
use App\Models\EnrollmentResult;
use App\Models\OrderItem;
use App\Models\OrderItemInstallment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything about one enrollment's course, loaded once (about a dozen queries, whatever the
 * course size), with every item's lock state worked out in memory.
 *
 * The lock rules live here and AccessService::check() reads them from here, so the course pages
 * and the single-item endpoints (play, join, submit) always agree. A map is a snapshot: build a
 * new one after changing progress, payments or the course.
 *
 *   Course order    stage (sort_order, id) > module (sort_order, id) > item (sort_order, id).
 *   Visible item    the item, its module and its stage are active, the stage isn't deleted, and
 *                   the item isn't cancelled for the batch (every class / exam row of it cancelled).
 *   Done            a progress row with status completed or excused (watching is not done).
 */
class CourseMap
{
    /** Videos, recordings and resources: they lock when the access period ends. */
    private const TIME_LIMITED = [ItemType::Recorded, ItemType::Resources];

    public readonly Enrollment $enrollment;

    /** @var Collection<int, CourseCurriculum> by id, in course order, deleted stages included */
    private Collection $stages;

    /** @var Collection<int, CourseModule> by id, in course order */
    private Collection $modules;

    /** @var array<int, int> module id => its position in the course */
    private array $modulePosition = [];

    /** @var Collection<int, CourseModuleItem> by id, every item of the course, in course order */
    private Collection $allItems;

    /** @var Collection<int, CourseModuleItem> visible items, in course order */
    private Collection $visible;

    /** @var Collection<int, Collection<int, BatchClassSchedule>> by item id, by start time */
    private Collection $classRows;

    /** @var Collection<int, Collection<int, BatchExam>> by item id */
    private Collection $examRows;

    /** @var Collection<int, EnrollmentProgress> by item id */
    private Collection $progress;

    private ?OrderItem $orderItem;

    /** @var list<int> */
    private array $cancelled;

    /** @var array<int, bool> recorded item id => an earlier video is not done yet */
    private array $earlierVideoLeft = [];

    /** @var array<int, string> item id => access reason */
    private array $reasons = [];

    // loaded only when a page needs them
    private ?Collection $assignmentRows = null;
    private ?Collection $results = null;
    private ?Collection $attendance = null;
    private bool $namesLoaded = false;
    private bool $liveInfoLoaded = false;

    public function __construct(Enrollment $enrollment)
    {
        $this->enrollment = $enrollment;

        $this->stages = CourseCurriculum::withTrashed()
            ->where('course_list_id', $enrollment->course_list_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->keyBy('id');
        $stagePosition = $this->stages->keys()->flip()->all();

        $this->modules = CourseModule::whereIn('course_curriculum_id', $this->stages->keys())->get()
            ->sort(fn (CourseModule $a, CourseModule $b) => [$stagePosition[$a->course_curriculum_id], $a->sort_order, $a->id]
                <=> [$stagePosition[$b->course_curriculum_id], $b->sort_order, $b->id])
            ->keyBy('id');
        $this->modulePosition = $this->modules->keys()->flip()->all();

        $this->allItems = CourseModuleItem::whereIn('course_modules_id', $this->modules->keys())->get()
            ->sort(fn (CourseModuleItem $a, CourseModuleItem $b) => [$this->modulePosition[$a->course_modules_id], $a->sort_order, $a->id]
                <=> [$this->modulePosition[$b->course_modules_id], $b->sort_order, $b->id])
            ->keyBy('id');

        $this->classRows = BatchClassSchedule::where('batch_list_id', $enrollment->batch_list_id)
            ->orderBy('class_start_time')->orderBy('id')
            ->get()->groupBy('course_module_items_id');
        $this->examRows = BatchExam::where('batch_list_id', $enrollment->batch_list_id)
            ->orderBy('id')->get()->groupBy('course_module_items_id');

        $this->progress = EnrollmentProgress::where('enrollment_id', $enrollment->id)->get()->keyBy('course_module_items_id');
        $this->orderItem = $enrollment->orderItem()->with('installments')->first();

        $this->cancelled = $this->findCancelled();
        $this->visible = $this->allItems->filter(fn (CourseModuleItem $item) => $this->isVisible($item))->values();

        $left = 0;
        foreach ($this->visible->where('item_type', ItemType::Recorded) as $video) {
            $this->earlierVideoLeft[$video->id] = $left > 0;
            if (!$this->isDone($video->id)) {
                $left++;
            }
        }
    }

    public static function for(Enrollment $enrollment): self
    {
        return new self($enrollment);
    }

    // ---- the course -------------------------------------------------------------------------

    /** @return Collection<int, CourseModuleItem> visible items, in course order */
    public function items(): Collection
    {
        return $this->visible;
    }

    public function item(int $itemId): ?CourseModuleItem
    {
        return $this->allItems->get($itemId);
    }

    /** @return list<int> items not offered to this batch (every class / exam row cancelled) */
    public function cancelledItemIds(): array
    {
        return $this->cancelled;
    }

    /** @return Collection<int, CourseCurriculum> active, not deleted stages, in course order */
    public function stages(): Collection
    {
        return $this->stages->filter(fn (CourseCurriculum $s) => $s->is_active && !$s->trashed())->values();
    }

    /** @return Collection<int, CourseModule> active modules of a visible stage, in course order */
    public function modules(?int $stageId = null): Collection
    {
        $stageIds = $this->stages()->pluck('id')->all();

        return $this->modules
            ->filter(fn (CourseModule $m) => $m->is_active && in_array($m->course_curriculum_id, $stageIds, true))
            ->when($stageId !== null, fn ($c) => $c->where('course_curriculum_id', $stageId))
            ->values();
    }

    public function module(int $moduleId): ?CourseModule
    {
        return $this->modules()->firstWhere('id', $moduleId);
    }

    /** @return Collection<int, CourseModuleItem> the module's visible items, in course order */
    public function moduleItems(int $moduleId): Collection
    {
        return $this->visible->where('course_modules_id', $moduleId)->values();
    }

    public function stageOf(CourseModule $module): ?CourseCurriculum
    {
        return $this->stages->get($module->course_curriculum_id);
    }

    public function moduleOf(CourseModuleItem $item): ?CourseModule
    {
        return $this->modules->get($item->course_modules_id);
    }

    // ---- access -----------------------------------------------------------------------------

    /** Can the student open this item now? One of the AccessService reasons. */
    public function reason(int $itemId): string
    {
        return $this->reasons[$itemId] ??= $this->work($itemId);
    }

    public function canOpen(int $itemId): bool
    {
        return $this->reason($itemId) === AccessService::OK;
    }

    /** Not locked by an unpaid installment (the module is before the one the next due opens). */
    public function moduleOpen(int $moduleId): bool
    {
        if (!$this->orderItem) {
            return false;
        }

        $gate = $this->orderItem->lockedAtModuleId();
        if ($gate === null) {
            return true;
        }

        // The gate module keeps its place even when hidden or in a deleted stage; if it's gone
        // altogether, stay locked rather than open the course.
        if (!isset($this->modulePosition[$gate], $this->modulePosition[$moduleId])) {
            return false;
        }

        return $this->modulePosition[$moduleId] < $this->modulePosition[$gate];
    }

    /** The unpaid installment that keeps modules locked, if any. */
    public function nextDue(): ?OrderItemInstallment
    {
        return $this->orderItem?->installments
            ->filter(fn (OrderItemInstallment $i) => $i->status !== InstallmentStatus::Paid)
            ->sortBy('sequence')
            ->first();
    }

    /** The first module the next unpaid installment keeps locked, if any. */
    public function lockedFromModule(): ?CourseModule
    {
        $gate = $this->orderItem?->lockedAtModuleId();

        return $gate ? $this->modules->get($gate) : null;
    }

    /** "Available from" of a recorded item for this batch (null = no date set). */
    public function releaseAt(CourseModuleItem $item): ?Carbon
    {
        return $this->classRows->get($item->id, collect())
            ->first(fn (BatchClassSchedule $row) => $row->status !== ClassStatus::Cancelled)
            ?->class_start_time;
    }

    // ---- progress ---------------------------------------------------------------------------

    public function progressRow(int $itemId): ?EnrollmentProgress
    {
        return $this->progress->get($itemId);
    }

    public function isDone(int $itemId): bool
    {
        return (bool) $this->progress->get($itemId)?->isDone();
    }

    public function isExcused(int $itemId): bool
    {
        return $this->progress->get($itemId)?->status === EnrollmentProgress::STATUS_EXCUSED;
    }

    public function resumeAt(int $itemId): ?int
    {
        return $this->progress->get($itemId)?->position_seconds;
    }

    /** Done and total of the given items; excused items leave both counts. @return array{done: int, total: int} */
    public function tally(Collection $items): array
    {
        $counted = $items->reject(fn (CourseModuleItem $i) => $this->isExcused($i->id));

        return [
            'done' => $counted->filter(fn (CourseModuleItem $i) => $this->isDone($i->id))->count(),
            'total' => $counted->count(),
        ];
    }

    // ---- batch rows -------------------------------------------------------------------------

    /** The live / recorded item's class row: the next one not cancelled and not over, else the last one. */
    public function classFor(CourseModuleItem $item): ?BatchClassSchedule
    {
        $this->loadLiveInfo();
        $rows = $this->classRows->get($item->id, collect())->filter(fn (BatchClassSchedule $r) => $r->status !== ClassStatus::Cancelled);

        return $rows->first(fn (BatchClassSchedule $r) => now()->lte($r->endsAt())) ?? $rows->last();
    }

    /** @return Collection<int, BatchClassSchedule> the item's class rows that aren't cancelled */
    public function classesFor(CourseModuleItem $item): Collection
    {
        $this->loadLiveInfo();

        return $this->classRows->get($item->id, collect())->filter(fn (BatchClassSchedule $r) => $r->status !== ClassStatus::Cancelled)->values();
    }

    /** The exam item's exam for this batch (the newest that isn't cancelled). */
    public function examFor(CourseModuleItem $item): ?BatchExam
    {
        return $this->examRows->get($item->id, collect())->filter(fn (BatchExam $e) => $e->status !== ExamStatus::Cancelled)->last();
    }

    /** The assignment item's assignment for this batch (the newest one given out; drafts stay hidden). */
    public function assignmentFor(CourseModuleItem $item): ?BatchAssignment
    {
        $this->assignmentRows ??= BatchAssignment::where('batch_list_id', $this->enrollment->batch_list_id)
            ->where('status', '!=', AssignmentStatus::Draft)
            ->orderBy('id')->get()->groupBy('course_module_items_id');

        return $this->assignmentRows->get($item->id, collect())->last();
    }

    public function resultFor(BatchAssignment|BatchExam $work): ?EnrollmentResult
    {
        $this->results ??= EnrollmentResult::where('enrollment_id', $this->enrollment->id)->get();
        $column = $work instanceof BatchExam ? 'batchwise_exam_id' : 'batchwise_assignment_id';

        return $this->results->first(fn (EnrollmentResult $r) => (int) $r->{$column} === (int) $work->id);
    }

    public function attendanceFor(BatchClassSchedule $class): ?ClassAttendance
    {
        $this->attendance ??= ClassAttendance::where('enrollment_id', $this->enrollment->id)->get()->keyBy('class_schedule_id');

        return $this->attendance->get($class->id);
    }

    // ---- names ------------------------------------------------------------------------------

    /** The item's title: its objective, else the name of what it shows. */
    public function title(CourseModuleItem $item): string
    {
        if (filled($item->objective)) {
            return $item->objective;
        }

        $this->loadNames();

        return match ($item->item_type) {
            ItemType::Recorded => $item->content?->name,
            ItemType::Resources => $item->resource?->name,
            ItemType::Exam => $this->examFor($item)?->title,
            ItemType::Assignment => $this->assignmentFor($item)?->title,
            ItemType::Live => $item->content?->name,
        } ?? ucfirst($item->item_type->value);
    }

    /** Loads the visible items' content and resource (names, thumbnails) in two queries. */
    public function loadNames(): void
    {
        if (!$this->namesLoaded) {
            $this->visible->load(['content:id,name,thumbnail,url,file_disk,file_path', 'resource:id,name']);
            $this->namesLoaded = true;
        }
    }

    /** Meeting link / recording of every class row, in one query, when a page needs them (the lock check doesn't). */
    private function loadLiveInfo(): void
    {
        if (!$this->liveInfoLoaded) {
            $this->classRows->flatten()->pipe(fn ($rows) => (new \Illuminate\Database\Eloquent\Collection($rows->all()))->load('liveInfo'));
            $this->liveInfoLoaded = true;
        }
    }

    // ---- internals --------------------------------------------------------------------------

    private function work(int $itemId): string
    {
        if (!$this->enrollment->isActive()) {
            return AccessService::NOT_ENROLLED;
        }

        $item = $this->allItems->get($itemId);
        if (!$item) {
            return AccessService::NOT_ENROLLED;   // not part of this course
        }

        $module = $this->modules->get($item->course_modules_id);
        $stage = $this->stages->get($module->course_curriculum_id);
        if (!$stage || $stage->trashed() || !$this->isVisible($item)) {
            return AccessService::INACTIVE;
        }

        if (in_array($item->item_type, self::TIME_LIMITED, true) && !$this->enrollment->hasContentAccess()) {
            return AccessService::ACCESS_EXPIRED;
        }

        if (!$this->moduleOpen($module->id)) {
            return AccessService::PAYMENT_LOCKED;
        }

        if ($item->item_type === ItemType::Recorded) {
            if ($this->releaseAt($item)?->isFuture()) {
                return AccessService::NOT_RELEASED;
            }
            if ($this->earlierVideoLeft[$item->id] ?? false) {
                return AccessService::PREVIOUS_INCOMPLETE;
            }
        }

        return AccessService::OK;
    }

    private function isVisible(CourseModuleItem $item): bool
    {
        $module = $this->modules->get($item->course_modules_id);
        $stage = $module ? $this->stages->get($module->course_curriculum_id) : null;

        return $item->is_active && $module?->is_active && $stage?->is_active && !$stage->trashed()
            && !in_array($item->id, $this->cancelled, true);
    }

    /**
     * Item ids whose every class row (or every exam row) for the batch is cancelled. An item with
     * a cancelled row AND a newer row that isn't cancelled still counts.
     *
     * @return list<int>
     */
    private function findCancelled(): array
    {
        $ids = [];
        foreach ([[$this->classRows, ClassStatus::Cancelled], [$this->examRows, ExamStatus::Cancelled]] as [$groups, $cancelled]) {
            foreach ($groups as $itemId => $rows) {
                if ($rows->every(fn ($row) => $row->status === $cancelled)) {
                    $ids[] = (int) $itemId;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
