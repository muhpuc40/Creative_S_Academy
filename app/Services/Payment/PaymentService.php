<?php

namespace App\Services\Payment;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemInstallment;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student can pay for one or several dues (order_item_installments) at once, even across
 * different courses in the same order, as one bKash/Nagad/bank transfer - the student (or the
 * frontend) selects which dues this payment covers, the amount must match them exactly, and
 * everything else (enrollment, module access, affiliate conversion) falls out of which specific
 * installment rows end up "paid", not of the order as a whole - see EnrollmentService, which this
 * class shares with CheckoutService (a 100%-discounted order reaches the same paid-installment
 * state without ever going through here, so that logic can't live only in this class).
 */
class PaymentService
{
    public function __construct(private readonly EnrollmentService $enrollment)
    {
    }

    /**
     * Student submits proof of a payment. Reserves the selected dues (unpaid -> pending) against
     * this one payment_list row so they can't also be claimed by a second submission while this
     * one is awaiting review.
     */
    public function submit(Student $student, int $orderId, array $installmentIds, array $data): Payment
    {
        return DB::transaction(function () use ($student, $orderId, $installmentIds, $data) {
            $order = Order::where('student_id', $student->id)->lockForUpdate()->find($orderId);
            if (!$order) {
                throw ValidationException::withMessages(['order_id' => 'Order not found.']);
            }
            if (!in_array($order->status, [OrderStatus::Pending, OrderStatus::PartiallyPaid], true)) {
                throw ValidationException::withMessages(['order_id' => 'This order has nothing left to pay, or is no longer payable.']);
            }
            if ($order->expires_at && $order->expires_at->isPast()) {
                throw ValidationException::withMessages(['order_id' => 'This order has expired. Please checkout again.']);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, OrderItemInstallment> $installments */
            $installments = OrderItemInstallment::whereIn('id', $installmentIds)
                ->whereHas('item', fn ($q) => $q->where('order_list_id', $order->id))
                ->lockForUpdate()
                ->get();

            if ($installments->count() !== count(array_unique($installmentIds))) {
                throw ValidationException::withMessages(['installment_ids' => 'One or more selected dues do not belong to this order.']);
            }

            $notUnpaid = $installments->first(fn (OrderItemInstallment $i) => $i->status !== InstallmentStatus::Unpaid);
            if ($notUnpaid) {
                throw ValidationException::withMessages(['installment_ids' => "\"{$notUnpaid->title}\" is already paid, or already awaiting verification."]);
            }

            $expected = round((float) $installments->sum('amount'), 2);
            $submitted = round((float) $data['amount'], 2);
            if (abs($expected - $submitted) > 0.01) {
                throw ValidationException::withMessages(['amount' => "The amount must be exactly {$expected} for the dues you selected (you sent {$submitted})."]);
            }

            if (!empty($data['trx_id'])) {
                $dupe = Payment::where('method', $data['method'])
                    ->where('trx_id', $data['trx_id'])
                    ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Verified])
                    ->exists();
                if ($dupe) {
                    throw ValidationException::withMessages(['trx_id' => 'This transaction ID has already been submitted.']);
                }
            }

            $payment = Payment::create([
                'order_list_id' => $order->id,
                'student_id' => $student->id,
                'method' => $data['method'],
                'payment_account_id' => $data['payment_account_id'] ?? null,
                'provider' => $data['provider'] ?? null,
                'sender_account' => $data['sender_account'] ?? null,
                'trx_id' => $data['trx_id'] ?? null,
                'proof_image' => $data['proof_image'] ?? null,
                'amount' => $submitted,
                'paid_at' => $data['paid_at'] ?? now(),
                'status' => PaymentStatus::Pending,
                'note' => $data['note'] ?? null,
            ]);

            OrderItemInstallment::whereIn('id', $installmentIds)->update([
                'status' => InstallmentStatus::Pending,
                'payment_id' => $payment->id,
            ]);

            return $payment->load('installments.item.course', 'order');
        });
    }

    /**
     * Admin verifies a payment: the dues it covers become paid, the order's totals/status
     * recalculate, and each affected course's enrollment/affiliate state is synced - see
     * syncItemState(). One payment can touch several order_items (several courses) at once.
     */
    public function verify(Admin $admin, Payment $payment): Payment
    {
        return DB::transaction(function () use ($admin, $payment) {
            /** @var Payment $payment */
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => 'Only a pending payment can be verified.']);
            }

            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

            /** @var \Illuminate\Database\Eloquent\Collection<int, OrderItemInstallment> $installments */
            $installments = OrderItemInstallment::where('payment_id', $payment->id)->lockForUpdate()->get();
            foreach ($installments as $installment) {
                $installment->update(['status' => InstallmentStatus::Paid, 'paid_at' => now()]);
            }

            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($payment->order_list_id);
            $newPaidAmount = round((float) $order->paid_amount + (float) $payment->amount, 2);
            $newStatus = $newPaidAmount >= (float) $order->total - 0.01 ? OrderStatus::Paid : OrderStatus::PartiallyPaid;

            $order->update([
                'paid_amount' => $newPaidAmount,
                'status' => $newStatus,
                'completed_at' => $newStatus === OrderStatus::Paid ? ($order->completed_at ?? now()) : $order->completed_at,
            ]);

            $itemIds = $installments->pluck('order_item_id')->unique();
            foreach ($itemIds as $itemId) {
                $item = OrderItem::with('installments')->find($itemId);
                if ($item) {
                    $this->enrollment->syncItemState($item, $order);
                }
            }

            return $payment->fresh(['installments.item.course', 'order']);
        });
    }

    /**
     * Admin rejects a payment: whatever it was reserving goes back to unpaid, free to be paid
     * again (by this payment's student, in a new submission).
     */
    public function reject(Admin $admin, Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($admin, $payment, $reason) {
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => 'Only a pending payment can be rejected.']);
            }

            $payment->update([
                'status' => PaymentStatus::Rejected,
                'reject_reason' => $reason,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

            OrderItemInstallment::where('payment_id', $payment->id)->update([
                'status' => InstallmentStatus::Unpaid,
                'payment_id' => null,
            ]);

            return $payment->fresh(['installments.item.course', 'order']);
        });
    }
}
