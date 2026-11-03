<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One course inside an order, at the price it was bought for. Never edited after checkout. */
class OrderItem extends Model
{
    protected $table = 'order_items';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payment_plan' => PaymentPlan::class,
            'list_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'combo_discount' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'net_price' => 'decimal:2',
            'status' => OrderItemStatus::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_list_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_list_id')->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_list_id')->withTrashed();
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(CoursePrice::class, 'course_prices_id');
    }

    /** @return HasMany<OrderItemInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(OrderItemInstallment::class)->orderBy('sequence');
    }

    /** @return HasOne<Enrollment, $this> */
    public function enrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class, 'order_item_id');
    }

    /**
     * The module that currently blocks further progress in this course, or null if there isn't
     * one (every due so far is paid). Driven purely by what's been paid, independent of every
     * other course in the same order - see the installments' own due_before_module_id comment.
     */
    public function lockedAtModuleId(): ?int
    {
        $nextDue = $this->installments
            ->filter(fn (OrderItemInstallment $i) => $i->status !== InstallmentStatus::Paid)
            ->sortBy('sequence')
            ->first();

        return $nextDue?->due_before_module_id;
    }

    /**
     * Can the student open this module right now, given what they've paid so far?
     * course_modules.sort_order only orders modules WITHIN one curriculum stage (it resets to 1
     * for each stage - course_curriculum has its own sort_order for the stages themselves), so
     * "before/after" across the whole course has to compare (stage order, module order) pairs,
     * never module sort_order alone - that would wrongly treat module 1 of every stage as tied.
     */
    public function canAccessModule(int $moduleId): bool
    {
        $gateModuleId = $this->lockedAtModuleId();
        if ($gateModuleId === null) {
            return true;
        }
        if ($gateModuleId === $moduleId) {
            return false;
        }

        $gate = CourseModule::with('curriculum')->find($gateModuleId);
        $target = CourseModule::with('curriculum')->find($moduleId);
        if (!$gate || !$target) {
            return false;
        }

        if ((int) $target->curriculum->sort_order !== (int) $gate->curriculum->sort_order) {
            return (int) $target->curriculum->sort_order < (int) $gate->curriculum->sort_order;
        }

        return (int) $target->sort_order < (int) $gate->sort_order;
    }
}
