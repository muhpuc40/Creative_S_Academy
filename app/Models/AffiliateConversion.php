<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A course sold through an affiliate: one row per order_item. Made when the enrollment is created. */
class AffiliateConversion extends Model
{
    protected $table = 'affiliate_conversions';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'sale_amount' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(AffiliateAttribution::class, 'affiliate_attribution_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_list_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasOne<AffiliateCommission, $this> */
    public function commission(): HasOne
    {
        return $this->hasOne(AffiliateCommission::class, 'affiliate_conversion_id');
    }
}
