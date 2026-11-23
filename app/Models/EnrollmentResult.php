<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's submitted file and marks for one batch exam or assignment (no pass or fail). */
class EnrollmentResult extends Model
{
    public const TYPE_EXAM = 'exam';
    public const TYPE_ASSIGNMENT = 'assignment';

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';
    public const STATUS_ABSENT = 'absent';

    protected $table = 'enrolled_user_result';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'marks_obtained' => 'decimal:2',
            'total_marks' => 'integer',
            'graded_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(BatchExam::class, 'batchwise_exam_id')->withTrashed();
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(BatchAssignment::class, 'batchwise_assignment_id')->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseModuleItem::class, 'course_module_items_id');
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'graded_by');
    }
}
