<?php

namespace App\Models;

use App\Enums\OrderStatus;
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
     * How long a new unpaid order (or one whose only proof was rejected) waits for a payment.
     * After that the scheduled `orders:expire` command marks it expired - see
     * App\Console\Commands\ExpireUnpaidOrders, the one place orders expire.
     */
    public const PAYMENT_WINDOW_HOURS = 24;

    /**
     * Orders that still hold their courses (and their batch seats). Expiry is done by the
     * `orders:expire` command, so the stored status is always the truth: no date maths here.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderStatus::Pending, OrderStatus::PartiallyPaid, OrderStatus::Paid]);
    }

    /** What's still owed. 0 once fully paid. */
    public function dueAmount(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }
}
