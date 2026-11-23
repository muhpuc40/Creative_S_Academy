<?php

namespace App\Services\Enrollment;

use App\Enums\InstallmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlan;
use App\Models\CoursePrice;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Renewing a course's access period (videos, recordings and resources lock after access_expires_at).
 *
 *   When     from 15 days before access_expires_at, and any time after it.
 *   Price    the course's CURRENT price: renewal_fee, paid in full (no voucher, combo or installments).
 *   Order    an ordinary order with one item pointing at the enrollment (renews_enrollment_id),
 *            paid through the usual TrxID + admin verification, expiring unpaid like any order.
 *   Paid     EnrollmentService extends access_expires_at: the later of today and the old end,
 *            plus renewal_days. No new enrollment, no seat, no affiliate commission.
 */
class RenewalService
{
    public const OPENS_DAYS_BEFORE = 15;

    public const OK = 'ok';
    public const NOT_ENROLLED = 'not_enrolled';
    public const NOT_OFFERED = 'not_offered';
    public const NOT_NEEDED = 'not_needed';
    public const TOO_EARLY = 'too_early';
    public const PENDING = 'pending';

    /** The course's current price, if it offers renewal. */
    public function priceFor(Enrollment $enrollment): ?CoursePrice
    {
        $price = CoursePrice::where('course_list_id', $enrollment->course_list_id)->where('is_active', true)->first();

        return $price?->offersRenewal() ? $price : null;
    }

    /** From when renewing is possible, or null when the enrollment has no access limit. */
    public function opensAt(Enrollment $enrollment): ?CarbonInterface
    {
        return $enrollment->access_expires_at?->copy()->subDays(self::OPENS_DAYS_BEFORE);
    }

    /** A renewal order of this enrollment still waiting for payment, if any. */
    public function pendingOrder(Enrollment $enrollment): ?Order
    {
        return Order::live()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::PartiallyPaid])
            ->whereHas('items', fn ($q) => $q->where('renews_enrollment_id', $enrollment->id)->where('status', OrderItemStatus::Active))
            ->latest('id')
            ->first();
    }

    /** ok, or why the student can't renew now. */
    public function eligibility(Enrollment $enrollment): string
    {
        if (!$enrollment->isActive()) {
            return self::NOT_ENROLLED;
        }
        if (!$enrollment->access_expires_at) {
            return self::NOT_NEEDED;   // this course never locks
        }
        if (!$this->priceFor($enrollment)) {
            return self::NOT_OFFERED;
        }
        if (now()->lt($this->opensAt($enrollment))) {
            return self::TOO_EARLY;
        }
        if ($this->pendingOrder($enrollment)) {
            return self::PENDING;
        }

        return self::OK;
    }

    /**
     * Creates the renewal order (call when eligibility() is OK; it is checked again under a lock).
     * A free renewal (fee 0) is paid at once and extends access straight away.
     *
     * @return Order|string the order, or the reason it can't be made
     */
    public function create(Enrollment $enrollment, EnrollmentService $enrollments): Order|string
    {
        return DB::transaction(function () use ($enrollment, $enrollments) {
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            $reason = $this->eligibility($enrollment);
            if ($reason !== self::OK) {
                return $reason;
            }

            $price = $this->priceFor($enrollment);
            $fee = round((float) $price->renewal_fee, 2);
            $free = $fee <= 0.0;

            $order = Order::create([
                'order_no' => Order::nextOrderNo(),
                'student_id' => $enrollment->student_id,
                'status' => $free ? OrderStatus::Paid : OrderStatus::Pending,
                'subtotal' => $fee,
                'total' => $fee,
                'paid_amount' => 0,
                'expires_at' => $free ? null : now()->addHours(Order::PAYMENT_WINDOW_HOURS),
                'completed_at' => $free ? now() : null,
                'note' => "Renewal: {$price->renewal_days} more days of access",
            ]);

            $item = $order->items()->create([
                'course_list_id' => $enrollment->course_list_id,
                'batch_list_id' => $enrollment->batch_list_id,
                'course_prices_id' => $price->id,
                'renews_enrollment_id' => $enrollment->id,
                'payment_plan' => PaymentPlan::Full,
                'list_price' => $fee,
                'sale_price' => $fee,
                'net_price' => $fee,
                'status' => OrderItemStatus::Active,
            ]);

            $item->installments()->create([
                'sequence' => 1,
                'title' => 'Renewal',
                'amount' => $fee,
                'status' => $free ? InstallmentStatus::Paid : InstallmentStatus::Unpaid,
                'paid_at' => $free ? now() : null,
            ]);

            if ($free) {
                $enrollments->syncItemState($item->load('installments'), $order);
            }

            return $order->load(['items.course', 'items.batch', 'items.installments']);
        });
    }
}
