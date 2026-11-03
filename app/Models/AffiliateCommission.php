<?php

namespace App\Models;

use App\Enums\AmountType;
use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission earned from one conversion. The rate is COPIED from affiliate_settings at the
 * time of the sale, so a later change to the program rate never touches commissions already
 * earned. Created alongside its conversion as "pending"; PaymentService flips it to "available"
 * once the student has fully paid for that course - see available_at's own comment.
 */
class AffiliateCommission extends Model
{
    protected $table = 'affiliate_commissions';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate_type' => AmountType::class,
            'status' => CommissionStatus::class,
            'rate_value' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'available_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversion::class, 'affiliate_conversion_id');
    }

    // payout() relation (affiliate_payout_id -> affiliate_payouts) is added by the future
    // Payout API, once an AffiliatePayout model exists for that table.
}
