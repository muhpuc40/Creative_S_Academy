<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due of a course: full payment is sequence 1 with no course_payment_rule_id, an
 * installment plan is one row per rule. due_before_module_id null = due at enrollment.
 */
class OrderItemInstallment extends Model
{
    protected $table = 'order_item_installments';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CoursePaymentRule::class, 'course_payment_rule_id');
    }

    public function dueBeforeModule(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'due_before_module_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
