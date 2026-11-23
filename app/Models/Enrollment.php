<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One course a student has access to. One row per order_item - see the migration's comment. */
class Enrollment extends Model
{
    protected $table = 'enrolled_user_list';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'access_expires_at' => 'datetime',
            'progress_percent' => 'decimal:2',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_list_id')->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_list_id')->withTrashed();
    }

    /** @return HasMany<EnrollmentProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(EnrollmentProgress::class);
    }

    /** @return HasMany<ClassAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(ClassAttendance::class);
    }

    /** @return HasMany<EnrollmentResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(EnrollmentResult::class);
    }

    /** Renewal order items that extend this enrollment. */
    public function renewals(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'renews_enrollment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === EnrollmentStatus::Active;
    }

    /** Can the student still open videos, recordings and resources? null expiry = no time limit. */
    public function hasContentAccess(): bool
    {
        return $this->isActive() && ($this->access_expires_at === null || $this->access_expires_at->isFuture());
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
