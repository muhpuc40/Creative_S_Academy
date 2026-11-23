<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
     * How long a new unpaid order (or one whose only payment was rejected) waits for a payment.
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

    /**
     * Creates the order with the next free order number. Two students checking out at the same
     * moment can pick the same number; the second insert then hits the unique index, and is
     * retried with a later number (inside a savepoint, so the caller's transaction carries on).
     */
    public static function createNumbered(array $attributes): self
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn () => static::create(['order_no' => static::nextOrderNo($attempt)] + $attributes));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }

    /** The next free order number of this month: CSA-2611-0001, CSA-2611-0002, ... ($skip: numbers to jump past, for a retry) */
    public static function nextOrderNo(int $skip = 0): string
    {
        $prefix = 'CSA-'.now()->format('ym').'-';
        $seq = static::where('order_no', 'like', $prefix.'%')->count() + 1 + $skip;

        do {
            $no = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (static::where('order_no', $no)->exists());

        return $no;
    }

    /** What's still owed. 0 once fully paid. */
    public function dueAmount(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }
}
