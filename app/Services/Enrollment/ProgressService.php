<?php

namespace App\Services\Enrollment;

use App\Models\Admin;
use App\Models\CourseModuleItem;
use App\Models\Enrollment;
use App\Models\EnrollmentProgress;
use InvalidArgumentException;

/**
 * Course progress of one enrollment.
 *
 *   Counted items   every visible item of the course (see CourseMap::items()), minus
 *                   items the admin excused for this student.
 *   Module points   the module's milestone_value, split equally across its counted items.
 *                   If every module's milestone_value is 0, each module counts equally.
 *   Progress %      points earned / points of all modules that have counted items x 100.
 *   Completed       completed_at is set the first time progress reaches 100%, and kept.
 *
 * The true record is enrolled_user_course_progress; progress_percent on the enrollment is a
 * saved copy so lists never recalculate.
 */
class ProgressService
{
    /**
     * Mark an item done (marked | attended | recording | opened | submitted). Doing it again is
     * harmless. An item the admin had excused becomes completed.
     */
    /** $map: the CourseMap the caller already built for this enrollment, if any (saves loading the course again). */
    public function complete(Enrollment $enrollment, CourseModuleItem $item, string $via, ?CourseMap $map = null): EnrollmentProgress
    {
        $this->assertSameCourse($enrollment, $item);

        $row = EnrollmentProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'course_module_items_id' => $item->id,
        ]);

        if ($row->status !== EnrollmentProgress::STATUS_COMPLETED) {
            $row->fill([
                'course_modules_id' => $item->course_modules_id,
                'status' => EnrollmentProgress::STATUS_COMPLETED,
                'completed_via' => $via,
                'marked_by' => null,
                'completed_at' => now(),
            ])->save();
        }

        $enrollment->forceFill(['last_activity_at' => now()])->save();
        $this->recalculate($enrollment, $map);

        return $row;
    }

    /**
     * Where the student stopped a video (a recorded item, or a live item's recording): kept for
     * resuming. Never changes whether the item is done.
     */
    public function savePosition(Enrollment $enrollment, CourseModuleItem $item, int $seconds): EnrollmentProgress
    {
        $this->assertSameCourse($enrollment, $item);

        $row = EnrollmentProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'course_module_items_id' => $item->id,
        ]);

        if (!$row->exists) {
            $row->fill(['course_modules_id' => $item->course_modules_id, 'status' => EnrollmentProgress::STATUS_WATCHING]);
        }
        $row->position_seconds = max(0, $seconds);
        $row->save();

        // called every few seconds while watching: touch last_activity_at at most once a minute
        if (!$enrollment->last_activity_at || $enrollment->last_activity_at->lt(now()->subMinute())) {
            $enrollment->forceFill(['last_activity_at' => now()])->save();
        }

        return $row;
    }

    /** Where to resume this video, in seconds (null = from the start). */
    public function positionOf(Enrollment $enrollment, CourseModuleItem $item): ?int
    {
        return EnrollmentProgress::where('enrollment_id', $enrollment->id)
            ->where('course_module_items_id', $item->id)
            ->value('position_seconds');
    }

    /** The admin excuses this student from an item: it leaves their count. A completed item stays completed. */
    public function excuse(Enrollment $enrollment, CourseModuleItem $item, Admin $admin): EnrollmentProgress
    {
        $this->assertSameCourse($enrollment, $item);

        $row = EnrollmentProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'course_module_items_id' => $item->id,
        ]);

        if ($row->status !== EnrollmentProgress::STATUS_COMPLETED) {
            $row->fill([
                'course_modules_id' => $item->course_modules_id,
                'status' => EnrollmentProgress::STATUS_EXCUSED,
                'completed_via' => 'excused',
                'marked_by' => $admin->id,
                'completed_at' => now(),
            ])->save();
        }

        $this->recalculate($enrollment);

        return $row;
    }

    /** Works out progress_percent again and saves it (and completed_at at the first 100%). */
    public function recalculate(Enrollment $enrollment, ?CourseMap $map = null): float
    {
        $percent = $this->percent($enrollment, $map);

        $enrollment->forceFill([
            'progress_percent' => $percent,
            'completed_at' => $enrollment->completed_at ?? ($percent >= 100 ? now() : null),
        ])->save();

        return $percent;
    }

    /** Progress in percent (0-100, two decimals), without saving anything. */
    public function percent(Enrollment $enrollment, ?CourseMap $map = null): float
    {
        $map ??= CourseMap::for($enrollment);   // only its course structure is used: progress is read fresh below

        $rows = EnrollmentProgress::where('enrollment_id', $enrollment->id)->get(['course_module_items_id', 'status']);
        $excused = $rows->where('status', EnrollmentProgress::STATUS_EXCUSED)->pluck('course_module_items_id')->all();
        $done = $rows->where('status', EnrollmentProgress::STATUS_COMPLETED)->pluck('course_module_items_id')->all();

        $modules = $map->items()
            ->reject(fn(CourseModuleItem $i) => in_array($i->id, $excused, true))
            ->groupBy('course_modules_id');

        if ($modules->isEmpty()) {
            return 0.0;
        }

        $values = $modules->keys()->mapWithKeys(fn ($id) => [$id => (int) $map->moduleOf($modules[$id]->first())?->milestone_value]);
        $equal = $values->sum() <= 0;   // no milestone values set: every module counts the same

        $earned = 0.0;
        $total = 0.0;
        foreach ($modules as $moduleId => $items) {
            $weight = $equal ? 1.0 : (float) ($values[$moduleId] ?? 0);
            $total += $weight;
            $earned += $weight * $items->whereIn('id', $done)->count() / $items->count();
        }

        return $total > 0 ? round(min(100, $earned / $total * 100), 2) : 0.0;
    }

    private function assertSameCourse(Enrollment $enrollment, CourseModuleItem $item): void
    {
        $courseId = $item->module?->curriculum()->withTrashed()->value('course_list_id');

        if ((int) $courseId !== (int) $enrollment->course_list_id) {
            throw new InvalidArgumentException('This item belongs to another course.');
        }
    }
}
