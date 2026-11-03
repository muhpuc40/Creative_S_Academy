<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a student says they sent (payment_list). One payment can cover several dues at once,
 * even across different courses in the same order - see installments() and PaymentService.
 * Never deleted: a wrong submission is rejected, not removed.
 */
class Payment extends Model
{
    protected $table = 'payment_list';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** Link of the uploaded proof image (or null). */
    protected function proofImageUrl(): Attribute
    {
        return Attribute::get(fn () => Media::url($this->proof_image));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_list_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    /**
     * The dues (order_item_installments, possibly across several courses) this payment covers.
     * @return HasMany<OrderItemInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(OrderItemInstallment::class, 'payment_id');
    }
}
