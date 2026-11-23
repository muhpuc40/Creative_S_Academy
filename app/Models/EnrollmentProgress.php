<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One curriculum item a student has finished or been excused from (see the migration's comment). */
class EnrollmentProgress extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXCUSED = 'excused';

    protected $table = 'enrolled_user_course_progress';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseModuleItem::class, 'course_module_items_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_modules_id');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'marked_by');
    }
}
