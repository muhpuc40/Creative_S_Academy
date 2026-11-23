<?php

namespace App\Services\Enrollment;

use App\Models\CourseModuleItem;
use App\Models\Enrollment;
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
 * "Paid" = the module comes before the one the next unpaid installment opens (course order).
 * Items cancelled for the student's batch are not offered (INACTIVE) and never block the order.
 *
 * The rules themselves live in CourseMap, which works them out for a whole course at once (the
 * course pages use it directly); this class answers for one item.
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

    /** The student's active enrollment in this course, if any. */
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
        if (!$enrollment || !$enrollment->isActive()) {
            return self::NOT_ENROLLED;
        }

        return CourseMap::for($enrollment)->reason($item->id);
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
        return CourseMap::for($enrollment)->cancelledItemIds();
    }

    /**
     * Every active item of the enrollment's course, in course order (stage, module, item), with
     * hidden stages / modules and items cancelled for the batch left out.
     *
     * @return Collection<int, CourseModuleItem>
     */
    public function courseItems(Enrollment $enrollment): Collection
    {
        return CourseMap::for($enrollment)->items();
    }
}
