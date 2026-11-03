<?php

namespace App\Services\Enrollment;

use App\Enums\AmountType;
use App\Enums\AttributionStatus;
use App\Enums\CommissionStatus;
use App\Enums\ConversionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InstallmentStatus;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommission;
use App\Models\AffiliateConversion;
use App\Models\AffiliateSetting;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemInstallment;

/**
 * What one course's payment state means, independent of every other course in the same order -
 * shared by PaymentService (a verified payment) and CheckoutService (an order born already paid
 * in full, e.g. a 100% voucher) so enrollment/affiliate logic is written exactly once. Caller is
 * responsible for the surrounding DB transaction; this class only ever touches rows for the one
 * OrderItem it's given, and every write here is idempotent - safe to call again for an item whose
 * state didn't actually change.
 */
class EnrollmentService
{
    /**
     * Master-plan core:
     *   - As soon as this item has at least one paid installment, it's enrolled (one row in
     *     enrolled_user_list, per order_item - module access then follows purely from
     *     OrderItem::canAccessModule(), which looks at THIS item's own installments).
     *   - The FIRST time it gets enrolled, an affiliate_conversions row (+ a "pending" commission)
     *     is made, if this order carries an attribution - "made when the enrollment is created",
     *     per that table's own comment.
     *   - Once every installment for this item is paid, its commission (if any) is released from
     *     "pending" to "available" - see available_at's own comment on why this isn't the same
     *     moment as enrollment for an installment-plan course. For an item that's born fully paid
     *     in one shot (full payment plan, or a 100%-discounted order), both conditions are true
     *     on the very first call, so enrollment and commission release happen together - same as
     *     a single-installment course verified in one payment.
     */
    public function syncItemState(OrderItem $item, Order $order): void
    {
        $allPaid = $item->installments->isNotEmpty()
            && $item->installments->every(fn (OrderItemInstallment $i) => $i->status === InstallmentStatus::Paid);
        $anyPaid = $item->installments->contains(fn (OrderItemInstallment $i) => $i->status === InstallmentStatus::Paid);

        if ($anyPaid) {
            $alreadyEnrolled = Enrollment::where('order_item_id', $item->id)->exists();

            if (!$alreadyEnrolled) {
                Enrollment::create([
                    'student_id' => $order->student_id,
                    'order_item_id' => $item->id,
                    'course_list_id' => $item->course_list_id,
                    'batch_list_id' => $item->batch_list_id,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now(),
                ]);

                $this->createAffiliateConversion($item, $order);
            }
        }

        if ($allPaid) {
            $this->releaseCommission($item);
        }
    }

    /** One row per order_item, made once, the moment it's first enrolled. */
    private function createAffiliateConversion(OrderItem $item, Order $order): void
    {
        if (!$order->affiliate_attribution_id) {
            return;
        }

        $attribution = AffiliateAttribution::find($order->affiliate_attribution_id);
        if (!$attribution) {
            return;
        }

        $settings = AffiliateSetting::current();

        $conversion = AffiliateConversion::create([
            'affiliate_id' => $attribution->affiliate_id,
            'affiliate_attribution_id' => $attribution->id,
            'order_list_id' => $order->id,
            'order_item_id' => $item->id,
            'student_id' => $order->student_id,
            'sale_amount' => $item->net_price,
            'status' => ConversionStatus::Confirmed,
            'converted_at' => now(),
        ]);

        $commissionAmount = $settings->commission_type === AmountType::Percent
            ? round((float) $item->net_price * (float) $settings->commission_value / 100, 2)
            : min((float) $settings->commission_value, (float) $item->net_price);

        AffiliateCommission::create([
            'affiliate_id' => $attribution->affiliate_id,
            'affiliate_conversion_id' => $conversion->id,
            'rate_type' => $settings->commission_type,
            'rate_value' => $settings->commission_value,
            'base_amount' => $item->net_price,
            'amount' => $commissionAmount,
            'status' => CommissionStatus::Pending,
        ]);

        if ($attribution->status !== AttributionStatus::Converted) {
            $attribution->update(['status' => AttributionStatus::Converted]);
        }
    }

    /**
     * Flips a "pending" commission to "available" once its course is fully paid. available_at is
     * set to now + hold_days: the commission is credited now, but a future Payout API is where
     * admin would actually filter on available_at having passed before paying it out.
     */
    private function releaseCommission(OrderItem $item): void
    {
        $conversion = AffiliateConversion::where('order_item_id', $item->id)->first();
        if (!$conversion) {
            return;
        }

        $commission = AffiliateCommission::where('affiliate_conversion_id', $conversion->id)->first();
        if (!$commission || $commission->status !== CommissionStatus::Pending) {
            return;
        }

        $holdDays = (int) AffiliateSetting::current()->hold_days;
        $commission->update([
            'status' => CommissionStatus::Available,
            'available_at' => now()->addDays($holdDays),
        ]);
    }
}
