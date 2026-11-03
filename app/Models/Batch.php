<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One run of a course, for example "Full Stack - Batch 12".
 *
 * @property int|null $seat_limit null = unlimited seats (the integer cast keeps null as null)
 */
class Batch extends Model
{
    use SoftDeletes;

    protected $table = 'batch_list';
    protected $guarded = ['id'];

    /** Same defaults as the database, so a new model is correct before it is reloaded. */
    protected $attributes = ['status' => 'upcoming', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'approx_end_date' => 'date',
            'enroll_deadline' => 'date',
            'extended_enroll_deadline' => 'date',
            'seat_limit' => 'integer',
            'status' => BatchStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Batches a new student can still join: active, not finished, deadline not passed. */
    public function scopeOpenForEnrollment(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->whereIn('status', [BatchStatus::Upcoming->value, BatchStatus::Running->value])
            ->where(function (Builder $q) use ($today) {
                $q->where(fn(Builder $n) => $n->whereNull('enroll_deadline')->whereNull('extended_enroll_deadline'))
                    ->orWhere('enroll_deadline', '>=', $today)
                    ->orWhere('extended_enroll_deadline', '>=', $today);
            });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_list_id')->withTrashed();
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id')->withTrashed();
    }

    public function classes(): HasMany
    {
        return $this->hasMany(BatchClassSchedule::class, 'batch_list_id')->orderBy('class_start_time');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(BatchExam::class, 'batch_list_id')->orderBy('exam_date')->orderBy('start_time');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BatchAssignment::class, 'batch_list_id')->orderBy('due_at');
    }

    /** The last day a student can enroll: the extended date wins when it is later. */
    public function lastEnrollDate(): ?\Illuminate\Support\Carbon
    {
        $dates = array_filter([$this->enroll_deadline, $this->extended_enroll_deadline]);

        return $dates ? collect($dates)->max() : null;
    }

    /**
     * Seats already taken: a course sale (order_items.status = active) under a live order -
     * pending, partially paid or paid (Order::scopeLive()). An expired or cancelled order frees
     * its seat; unpaid orders are expired by the scheduled `orders:expire` command.
     */
    public function seatsTaken(): int
    {
        return OrderItem::where('batch_list_id', $this->id)
            ->where('status', OrderItemStatus::Active)
            ->whereHas('order', fn(Builder $q) => $q->live())
            ->count();
    }

    /** Seats still free. null = unlimited. */
    public function seatsLeft(): ?int
    {
        return $this->seat_limit === null ? null : max($this->seat_limit - $this->seatsTaken(), 0);
    }

    /** Can a student enroll today? (Seats are checked later, with the enrollments.) */
    public function isEnrollmentOpen(): bool
    {
        if (!$this->is_active || in_array($this->status, [BatchStatus::Completed, BatchStatus::Cancelled], true)) {
            return false;
        }

        $last = $this->lastEnrollDate();

        return $last === null || now()->startOfDay()->lte($last);
    }
}
