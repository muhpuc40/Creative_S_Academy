<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student at one live class. No row for a held class = absent. */
class ClassAttendance extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_LATE = 'late';
    public const STATUS_EXCUSED = 'excused';

    protected $table = 'enrolled_user_class_attendance';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_joined_at' => 'datetime',
            'last_joined_at' => 'datetime',
            'join_count' => 'integer',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(BatchClassSchedule::class, 'class_schedule_id')->withTrashed();
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'marked_by');
    }
}
