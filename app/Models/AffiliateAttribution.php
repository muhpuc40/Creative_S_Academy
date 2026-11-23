<?php

namespace App\Models;

use App\Enums\AttributionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "This student belongs to that affiliate." Made when a visitor with an affiliate cookie registers. */
class AffiliateAttribution extends Model
{
    protected $table = 'affiliate_attributions';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AttributionStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(AffiliateClick::class, 'affiliate_click_id');
    }

    /**
     * Still earning: the referral window (expires_at) has not ended. Every purchase inside it is
     * credited, so a referral that already converted once (status = converted) keeps counting.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [AttributionStatus::Open, AttributionStatus::Converted])
            ->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return in_array($this->status, [AttributionStatus::Open, AttributionStatus::Converted], true)
            && $this->expires_at?->isFuture();
    }
}
