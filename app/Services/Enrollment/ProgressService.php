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
 *   Counted items   every active item of the course (see AccessService::courseItems()), minus
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
    public function __construct(private AccessService $access)
    {
    }

    /**
     * Mark an item done (marked | attended | recording | opened | submitted). Doing it again is
     * harmless. An item the admin had excused becomes completed.
     */
    public function complete(Enrollment $enrollment, CourseModuleItem $item, string $via): EnrollmentProgress
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
        $this->recalculate($enrollment);

        return $row;
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
    public function recalculate(Enrollment $enrollment): float
    {
        $percent = $this->percent($enrollment);

        $enrollment->forceFill([
            'progress_percent' => $percent,
            'completed_at' => $enrollment->completed_at ?? ($percent >= 100 ? now() : null),
        ])->save();

        return $percent;
    }

    /** Progress in percent (0-100, two decimals), without saving anything. */
    public function percent(Enrollment $enrollment): float
    {
        $rows = EnrollmentProgress::where('enrollment_id', $enrollment->id)->get(['course_module_items_id', 'status']);
        $excused = $rows->where('status', EnrollmentProgress::STATUS_EXCUSED)->pluck('course_module_items_id')->all();
        $done = $rows->where('status', EnrollmentProgress::STATUS_COMPLETED)->pluck('course_module_items_id')->all();

        $modules = $this->access->courseItems($enrollment)
            ->reject(fn(CourseModuleItem $i) => in_array($i->id, $excused, true))
            ->groupBy('course_modules_id');

        if ($modules->isEmpty()) {
            return 0.0;
        }

        $values = \App\Models\CourseModule::whereIn('id', $modules->keys())->pluck('milestone_value', 'id');
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
