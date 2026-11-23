<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One curriculum item a student is watching, has finished, or was excused from (see the migration's comment). */
class EnrollmentProgress extends Model
{
    public const STATUS_WATCHING = 'watching';    // started a video, not done yet
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXCUSED = 'excused';

    /** The statuses that count as done. */
    public const DONE = [self::STATUS_COMPLETED, self::STATUS_EXCUSED];

    protected $table = 'enrolled_user_course_progress';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'position_seconds' => 'integer'];
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::DONE, true);
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
