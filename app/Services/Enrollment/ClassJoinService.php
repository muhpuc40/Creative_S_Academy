<?php

namespace App\Services\Enrollment;

use App\Enums\ClassStatus;
use App\Enums\ItemType;
use App\Models\BatchClassSchedule;
use App\Models\ClassAttendance;
use App\Models\Enrollment;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Joining a live class through the tracked link: who may join, when, and the attendance it records.
 * Used by both the join endpoint and the class list, so "can join now" always means the same thing.
 *
 *   window   opens 15 minutes before the start, closes when the class ends (start + duration)
 *   late     a first join more than 10 minutes after the start
 */
class ClassJoinService
{
    public const OPENS_MINUTES_BEFORE = 15;
    public const LATE_AFTER_MINUTES = 10;

    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const CANCELLED = 'cancelled';
    public const TOO_EARLY = 'too_early';
    public const ENDED = 'ended';
    public const NO_LINK = 'no_link';

    public function __construct(
        private AccessService $access,
        private ProgressService $progress,
    ) {}

    /** The student's active enrollment in the class's batch. */
    public function enrollmentFor(Student $student, BatchClassSchedule $class): ?Enrollment
    {
        return Enrollment::active()
            ->where('student_id', $student->id)
            ->where('batch_list_id', $class->batch_list_id)
            ->latest('id')
            ->first();
    }

    public function opensAt(BatchClassSchedule $class): CarbonInterface
    {
        return $class->class_start_time->copy()->subMinutes(self::OPENS_MINUTES_BEFORE);
    }

    /**
     * OK, or why not: not_found | cancelled | an AccessService reason | too_early | ended | no_link.
     */
    public function check(?Enrollment $enrollment, BatchClassSchedule $class): string
    {
        $class->loadMissing('item', 'liveInfo');

        if ($class->item?->item_type !== ItemType::Live) {
            return self::NOT_FOUND;
        }

        if (!$enrollment) {
            return AccessService::NOT_ENROLLED;
        }

        if ($class->status === ClassStatus::Cancelled) {
            return self::CANCELLED;
        }

        $access = $this->access->check($enrollment, $class->item);
        if ($access !== AccessService::OK) {
            return $access;
        }

        if (now()->lt($this->opensAt($class))) {
            return self::TOO_EARLY;
        }

        if (now()->gt($class->endsAt())) {
            return self::ENDED;
        }

        if (!$class->liveInfo?->meeting_url) {
            return self::NO_LINK;
        }

        return self::OK;
    }

    /**
     * Records the join (first time: present or late; again: join_count + 1, the first status and
     * an admin's "excused" stay) and counts the live class as done. Call only after check() is OK.
     */
    public function join(Enrollment $enrollment, BatchClassSchedule $class): ClassAttendance
    {
        return DB::transaction(function () use ($enrollment, $class) {
            // one join at a time per student: a double click cannot make two rows
            Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();

            $attendance = ClassAttendance::firstOrNew([
                'enrollment_id' => $enrollment->id,
                'class_schedule_id' => $class->id,
            ]);

            if (!$attendance->exists) {
                $late = now()->gt($class->class_start_time->copy()->addMinutes(self::LATE_AFTER_MINUTES));
                $attendance->status = $late ? ClassAttendance::STATUS_LATE : ClassAttendance::STATUS_PRESENT;
            }

            $attendance->first_joined_at ??= now();
            $attendance->last_joined_at = now();
            $attendance->join_count = (int) $attendance->join_count + 1;
            $attendance->save();

            $this->progress->complete($enrollment, $class->item, 'attended');

            return $attendance;
        });
    }
}
