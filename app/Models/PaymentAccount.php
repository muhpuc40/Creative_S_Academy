<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a student can send money: our bKash/Nagad number, our bank account, or cash instructions.
 * Managed by admins on Sales > Accounts. Only active ones are shown to students
 * (GET /api/v1/payment-accounts), in sort_order.
 */
class PaymentAccount extends Model
{
    protected $table = 'payment_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /** Payments students made into this account. Their history must survive, see PaymentAccountController::destroy(). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_account_id');
    }
}
