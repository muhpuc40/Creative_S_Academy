<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One checkout. Courses are on order_items; money actually sent is on payment_list (future Payment API). */
class Order extends Model
{
    protected $table = 'order_list';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'combo_discount' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_list_id');
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(PaymentCombo::class, 'payment_combo_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(AffiliateAttribution::class, 'affiliate_attribution_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_list_id');
    }

    /**
     * How long a new unpaid order (or one whose only proof was rejected) stays open for a
     * payment before checkout() releases it. Once any money is verified the order no longer
     * expires at all - see PaymentService::verify().
     */
    public const PAYMENT_WINDOW_HOURS = 24;

    /**
     * Orders that still hold their courses (and their batch seats): paid, partially paid, or
     * pending and either not yet expired OR with a proof awaiting admin review. Shared by
     * Batch::seatsTaken() and CheckoutService so "is this order still alive?" has one answer.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', [OrderStatus::PartiallyPaid, OrderStatus::Paid])
                ->orWhere(function (Builder $p) {
                    $p->where('status', OrderStatus::Pending)
                        ->where(function (Builder $w) {
                            $w->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now())
                                ->orWhereHas('payments', fn(Builder $pay) => $pay->where('status', PaymentStatus::Pending));
                        });
                });
        });
    }

    /** What's still owed. 0 once fully paid. */
    public function dueAmount(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }
}
