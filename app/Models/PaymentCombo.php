<?php

namespace App\Models;

use App\Enums\AmountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Support\Media;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Several courses sold together for one price (admin picks the courses and a price).
 *
 * price_type = Flat    -> price_value IS the combo's final price in taka (NOT a discount amount).
 * price_type = Percent -> price_value is a percent taken off the sum of the courses' current prices.
 *
 * The price is always calculated live from the courses' CURRENT prices (coursesTotal()), so editing
 * a course price later changes what a combo costs today. An order copies the result at purchase time,
 * so past orders are never affected.
 */
class PaymentCombo extends Model
{
    use SoftDeletes;

    protected $table = 'payment_combos';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_type' => AmountType::class,
            'price_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Link of the uploaded combo thumbnail (or null). */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn() => Media::url($this->thumbnail));
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'payment_combo_courses', 'payment_combo_id', 'course_list_id')
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** Admin picked it on, the deadline has not passed, and it has started (if a start date is set). */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where('ends_at', '>=', $now)
            ->where(fn(Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now));
    }

    public function isExpired(): bool
    {
        return $this->ends_at && $this->ends_at->isPast();
    }

    /** Sum of the courses' current price (after each course's own discount). Needs courses.currentPrice loaded. */
    public function coursesTotal(): float
    {
        return round($this->courses->sum(fn(Course $c) => (float) $c->currentPrice?->finalPrice()), 2);
    }

    /** What the student pays for the whole combo. Never more than the sum of the courses (never raises the price). */
    public function finalPrice(): float
    {
        $sum = $this->coursesTotal();

        $price = match ($this->price_type) {
            AmountType::Flat => (float) $this->price_value,              // the combo's total price in taka
            AmountType::Percent => $sum - ($sum * (float) $this->price_value / 100),
            default => $sum,
        };

        return round(min(max($price, 0), $sum), 2);
    }

    public function discountAmount(): float
    {
        return round(max($this->coursesTotal() - $this->finalPrice(), 0), 2);
    }
}
