<?php

namespace App\Services\Enrollment;

use App\Enums\ClassStatus;
use App\Enums\ExamStatus;
use App\Enums\ItemType;
use App\Models\BatchClassSchedule;
use App\Models\BatchExam;
use App\Models\CourseModuleItem;
use App\Models\Enrollment;
use App\Models\EnrollmentProgress;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Can this student open this curriculum item right now? check() answers with OK or the reason
 * it is locked, so the website can show the right message.
 *
 *   recorded video   paid  +  released (batch schedule "Available from")  +  every earlier
 *                    video done (in course order)  +  inside the access period
 *   resource         paid  +  inside the access period
 *   live class       paid             (its own join window is checked by the Join link)
 *   exam, assignment paid             (their own time windows are checked on submission)
 *   live recording   checkRecording(): the live item's checks + inside the access period
 *
 * "Paid" is OrderItem::canAccessModule(): the module is before the next unpaid installment.
 * Items cancelled for the student's batch are not offered (INACTIVE) and never block the order.
 */
class AccessService
{
    public const OK = 'ok';
    public const NOT_ENROLLED = 'not_enrolled';
    public const INACTIVE = 'inactive';
    public const ACCESS_EXPIRED = 'access_expired';
    public const PAYMENT_LOCKED = 'payment_locked';
    public const NOT_RELEASED = 'not_released';
    public const PREVIOUS_INCOMPLETE = 'previous_incomplete';

    /** Item types that stop opening when the access period ends. */
    private const TIME_LIMITED = [ItemType::Recorded, ItemType::Resources];

    /** The student's active enrollment in this course (the newest, if they bought it twice). */
    public function enrollmentFor(Student $student, int $courseId): ?Enrollment
    {
        return Enrollment::active()
            ->where('student_id', $student->id)
            ->where('course_list_id', $courseId)
            ->latest('id')
            ->first();
    }

    public function canOpen(?Enrollment $enrollment, CourseModuleItem $item): bool
    {
        return $this->check($enrollment, $item) === self::OK;
    }

    /** @return string one of the constants above */
    public function check(?Enrollment $enrollment, CourseModuleItem $item): string
    {
        $item->loadMissing('module.curriculum');
        $module = $item->module;
        $stage = $module?->curriculum;   // null when the stage was deleted (soft delete)

        if (!$enrollment || !$enrollment->isActive()) {
            return self::NOT_ENROLLED;
        }

        if (!$stage) {
            return self::INACTIVE;   // its stage was deleted
        }

        if ((int) $stage->course_list_id !== (int) $enrollment->course_list_id) {
            return self::NOT_ENROLLED;
        }

        if (
            !$item->is_active || !$module->is_active || !$stage->is_active
            || in_array($item->id, $this->cancelledItemIds($enrollment), true)
        ) {
            return self::INACTIVE;
        }

        if (in_array($item->item_type, self::TIME_LIMITED, true) && !$enrollment->hasContentAccess()) {
            return self::ACCESS_EXPIRED;
        }

        /** @var \App\Models\OrderItem */

        $orderItem = $enrollment->orderItem()->with('installments')->first();
        if (!$orderItem || !$orderItem->canAccessModule($module->id)) {
            return self::PAYMENT_LOCKED;
        }

        if ($item->item_type === ItemType::Recorded) {
            $releaseAt = $this->releaseTime($enrollment, $item);
            if ($releaseAt && $releaseAt->isFuture()) {
                return self::NOT_RELEASED;
            }

            if ($this->earlierVideosLeft($enrollment, $item) > 0) {
                return self::PREVIOUS_INCOMPLETE;
            }
        }

        return self::OK;
    }

    /** A live class's recording: the live item's own checks, plus the access period. */
    public function checkRecording(?Enrollment $enrollment, CourseModuleItem $liveItem): string
    {
        $result = $this->check($enrollment, $liveItem);

        if ($result === self::OK && !$enrollment->hasContentAccess()) {
            return self::ACCESS_EXPIRED;
        }

        return $result;
    }

    /**
     * Item ids not offered to this batch: every class row (live / recorded) or exam row the batch
     * has for that item is cancelled. An item with a cancelled row AND a newer live row still counts.
     *
     * @return list<int>
     */
    public function cancelledItemIds(Enrollment $enrollment): array
    {
        $batchId = $enrollment->batch_list_id;

        $fromRows = function (string $model, $cancelled) use ($batchId): Collection {
            $rows = $model::where('batch_list_id', $batchId)->get(['course_module_items_id', 'status']);
            $cancelledIds = $rows->where('status', $cancelled)->pluck('course_module_items_id');
            $offeredIds = $rows->where('status', '!=', $cancelled)->pluck('course_module_items_id');

            return $cancelledIds->diff($offeredIds);
        };

        return $fromRows(BatchClassSchedule::class, ClassStatus::Cancelled)
            ->concat($fromRows(BatchExam::class, ExamStatus::Cancelled))
            ->map(fn($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Every active item of the enrollment's course, in course order (stage, module, item), with
     * hidden stages / modules and items cancelled for the batch left out.
     *
     * @return Collection<int, CourseModuleItem>
     */
    public function courseItems(Enrollment $enrollment): Collection
    {
        $cancelled = $this->cancelledItemIds($enrollment);

        return CourseModuleItem::query()
            ->select('course_module_items.*')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_items.course_modules_id')
            ->join('course_curriculum', 'course_curriculum.id', '=', 'course_modules.course_curriculum_id')
            ->where('course_curriculum.course_list_id', $enrollment->course_list_id)
            ->whereNull('course_curriculum.deleted_at')
            ->where('course_curriculum.is_active', true)
            ->where('course_modules.is_active', true)
            ->where('course_module_items.is_active', true)
            ->when($cancelled, fn($q) => $q->whereNotIn('course_module_items.id', $cancelled))
            ->orderBy('course_curriculum.sort_order')->orderBy('course_curriculum.id')
            ->orderBy('course_modules.sort_order')->orderBy('course_modules.id')
            ->orderBy('course_module_items.sort_order')->orderBy('course_module_items.id')
            ->get();
    }

    /** "Available from" of a recorded item for this batch, or null if the batch has no row for it. */
    private function releaseTime(Enrollment $enrollment, CourseModuleItem $item): ?\Illuminate\Support\Carbon
    {
        return BatchClassSchedule::where('batch_list_id', $enrollment->batch_list_id)
            ->where('course_module_items_id', $item->id)
            ->where('status', '!=', ClassStatus::Cancelled)
            ->orderBy('class_start_time')
            ->first()?->class_start_time;
    }

    /** How many recorded videos before this one (in course order) are not done or excused yet. */
    private function earlierVideosLeft(Enrollment $enrollment, CourseModuleItem $item): int
    {
        $videos = $this->courseItems($enrollment)->where('item_type', ItemType::Recorded)->values();
        $position = $videos->search(fn(CourseModuleItem $v) => $v->id === $item->id);

        if ($position === false || $position === 0) {
            return 0;
        }

        $earlierIds = $videos->take($position)->pluck('id');
        $doneIds = EnrollmentProgress::where('enrollment_id', $enrollment->id)
            ->whereIn('course_module_items_id', $earlierIds)
            ->pluck('course_module_items_id');

        return $earlierIds->diff($doneIds)->count();
    }
}
