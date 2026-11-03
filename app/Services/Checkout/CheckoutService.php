<?php

namespace App\Services\Checkout;

use App\Enums\CourseStatus;
use App\Enums\InstallmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentStatus;
use App\Enums\VoucherStatus;
use App\Models\AffiliateAttribution;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CoursePrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentCombo;
use App\Models\PaymentVoucher;
use App\Models\Student;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quote + create order, for a plain cart OR a combo, with an optional voucher, in one place -
 * so the pricing math is never written twice. The student's own affiliate attribution (if any,
 * from AttributionService) is attached automatically; nothing the student sends controls it.
 */
class CheckoutService
{
    public function __construct(private readonly EnrollmentService $enrollment)
    {
    }

    /**
     * Read-only price preview. Nothing is written to the database - a student's own stale,
     * abandoned order is simply treated as not blocking (see the "owned" check in compute()),
     * the same way checkout() will treat it once it actually runs. The real cleanup (flipping
     * that order to Expired, freeing its voucher) only happens inside checkout()'s transaction.
     */
    public function quote(Student $student, ?int $comboId, array $items, ?string $voucherCode): array
    {
        return $this->present($this->compute($student, $comboId, $items, $voucherCode, lock: false));
    }

    /** Prices again (this time with row locks) and creates the order. */
    public function checkout(Student $student, ?int $comboId, array $items, ?string $voucherCode): Order
    {
        return DB::transaction(function () use ($student, $comboId, $items, $voucherCode) {
            $this->releaseExpiredOrders($student);

            $priced = $this->compute($student, $comboId, $items, $voucherCode, lock: true);

            return $this->persist($student, $priced);
        });
    }

    /**
     * Lazy cleanup, run at the top of every checkout() - there's no scheduler in this app, so a
     * stale order is only ever noticed (and only ever needs to be noticed) the next time this
     * same student tries to buy something. Flips this student's own Pending orders whose
     * expires_at has passed to Expired and frees any voucher they were holding, UNLESS the order
     * still has a payment awaiting admin review - that student clearly intends to complete the
     * purchase, so a slow admin queue must never silently cancel it out from under them.
     */
    private function releaseExpiredOrders(Student $student): void
    {
        $stale = Order::where('student_id', $student->id)
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', PaymentStatus::Pending))
            ->lockForUpdate()
            ->get();

        foreach ($stale as $order) {
            $order->update(['status' => OrderStatus::Expired]);

            if ($order->payment_voucher_id) {
                PaymentVoucher::where('id', $order->payment_voucher_id)
                    ->where('status', VoucherStatus::Used)
                    ->lockForUpdate()
                    ->update(['status' => VoucherStatus::Active, 'used_at' => null]);
            }
        }
    }

    /**
     * Validates everything and works out every number. Called twice: once with no locks for a
     * quote, once with row locks (inside the checkout() transaction) right before writing, so
     * a seat or a voucher that was free a second ago can't be sold twice.
     */
    private function compute(Student $student, ?int $comboId, array $items, ?string $voucherCode, bool $lock): array
    {
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'Select at least one course.']);
        }

        $courseIds = array_column($items, 'course_id');
        if (count($courseIds) !== count(array_unique($courseIds))) {
            throw ValidationException::withMessages(['items' => 'The same course was selected more than once.']);
        }

        $courses = Course::published()->with('currentPrice')->whereIn('id', $courseIds)->get()->keyBy('id');
        if ($courses->count() !== count($courseIds)) {
            throw ValidationException::withMessages(['items' => 'One or more selected courses are not available.']);
        }
        foreach ($courses as $course) {
            if (!$course->currentPrice) {
                throw ValidationException::withMessages(['items' => "\"{$course->title}\" has no price set and cannot be bought right now."]);
            }
        }

        $batchIds = array_column($items, 'batch_id');
        $batchQuery = Batch::query()->whereIn('id', $batchIds);
        if ($lock) {
            $batchQuery->lockForUpdate();
        }
        $batches = $batchQuery->get()->keyBy('id');
        if ($batches->count() !== count(array_unique($batchIds))) {
            throw ValidationException::withMessages(['items' => 'One or more selected batches do not exist.']);
        }

        // Already has a live order for one of these courses? Order::scopeLive() is the same rule
        // checkout() applies for real (releaseExpiredOrders() runs before this) and the same one
        // Batch::seatsTaken() uses, so quote() never promises something checkout() wouldn't allow.
        $owned = OrderItem::where('status', OrderItemStatus::Active)
            ->whereIn('course_list_id', $courseIds)
            ->whereHas('order', fn($q) => $q->where('student_id', $student->id)->live())
            ->first();
        if ($owned) {
            $title = $courses[$owned->course_list_id]->title ?? 'a selected course';
            throw ValidationException::withMessages(['items' => "You already have an order for \"{$title}\"."]);
        }

        // Combo, if buying one - the student's selection must be exactly its course set,
        // and every one of its courses must still be publicly buyable (same rule as the
        // public combo API: a combo with one bad course is hidden/unbuyable as a whole).
        $combo = null;
        if ($comboId) {
            $combo = PaymentCombo::active()->with('courses.currentPrice')->find($comboId);
            $comboBuyable = $combo && $combo->courses->isNotEmpty() && $combo->courses->every(
                fn(Course $c) => $c->status === CourseStatus::Published && $c->is_active && $c->currentPrice
            );
            if (!$comboBuyable) {
                throw ValidationException::withMessages(['combo_id' => 'This combo is not available right now.']);
            }

            $comboCourseIds = $combo->courses->pluck('id')->sort()->values()->all();
            $requestedIds = collect($courseIds)->sort()->values()->all();
            if ($comboCourseIds !== $requestedIds) {
                throw ValidationException::withMessages(['items' => "Your selection must match the combo's courses exactly."]);
            }

            // A combo is one bundled price - installments are per-course and don't make sense
            // split across a bundle, so a combo is always paid in full.
            foreach ($items as $row) {
                if (($row['payment_plan'] ?? 'full') === 'installment') {
                    throw ValidationException::withMessages(['items' => "A combo must be paid in full - installments aren't available on combo courses."]);
                }
            }
        }

        // Per-item validity + raw pricing (before combo/voucher discounts)
        $priced = [];
        foreach ($items as $row) {
            /** @var Course $course */
            $course = $courses[$row['course_id']];
            /** @var Batch $batch */
            $batch = $batches[$row['batch_id']];

            if ((int) $batch->course_list_id !== $course->id) {
                throw ValidationException::withMessages(['items' => "The selected batch does not belong to \"{$course->title}\"."]);
            }
            if (!$batch->isEnrollmentOpen()) {
                throw ValidationException::withMessages(['items' => "Enrollment for \"{$course->title}\"'s batch is closed."]);
            }
            if ($batch->seatsLeft() !== null && $batch->seatsLeft() < 1) {
                throw ValidationException::withMessages(['items' => "\"{$course->title}\"'s batch is full."]);
            }

            $plan = PaymentPlan::tryFrom((string) ($row['payment_plan'] ?? 'full')) ?? PaymentPlan::Full;

            $priced[] = [
                'course' => $course,
                'batch' => $batch,
                'payment_plan' => $plan,
                'course_price_id' => $course->currentPrice->id,
                'list_price' => (float) $course->currentPrice->actual_price,
                'sale_price' => $course->currentPrice->finalPrice(),
            ];
        }

        $subtotal = round(array_sum(array_column($priced, 'sale_price')), 2);

        // Combo discount, split across items in proportion to each one's sale price.
        $comboDiscountTotal = $combo ? $combo->discountAmount() : 0.0;
        $comboShares = $this->distribute($comboDiscountTotal, array_column($priced, 'sale_price'));
        foreach ($priced as $i => &$item) {
            $item['combo_discount'] = $comboShares[$i];
        }
        unset($item);

        // Voucher, applied to what's left after the combo discount, split the same way.
        $voucher = null;
        $voucherDiscountTotal = 0.0;
        if ($voucherCode) {
            $voucherQuery = PaymentVoucher::whereRaw('upper(code) = ?', [strtoupper($voucherCode)]);
            if ($lock) {
                $voucherQuery->lockForUpdate();
            }
            /** @var PaymentVoucher|null $voucher */
            $voucher = $voucherQuery->first();

            if (!$voucher || $voucher->student_id !== $student->id || !$voucher->canBeUsed()) {
                throw ValidationException::withMessages(['voucher_code' => 'This voucher code is not valid for your account.']);
            }

            $afterCombo = round($subtotal - $comboDiscountTotal, 2);
            $voucherDiscountTotal = $voucher->amountFor($afterCombo);
        }

        $remaining = array_map(fn($item) => round($item['sale_price'] - $item['combo_discount'], 2), $priced);
        $voucherShares = $this->distribute($voucherDiscountTotal, $remaining);
        foreach ($priced as $i => &$item) {
            $item['voucher_discount'] = $voucherShares[$i];
            $item['net_price'] = round($item['sale_price'] - $item['combo_discount'] - $item['voucher_discount'], 2);
        }
        unset($item);

        foreach ($priced as &$item) {
            $item['installments'] = $this->installmentsFor($item['course']->currentPrice, $item['net_price'], $item['payment_plan']);
        }
        unset($item);

        return [
            'items' => $priced,
            'combo' => $combo,
            'voucher' => $voucher,
            'subtotal' => $subtotal,
            'combo_discount' => round($comboDiscountTotal, 2),
            'voucher_discount' => round($voucherDiscountTotal, 2),
            'total' => round($subtotal - $comboDiscountTotal - $voucherDiscountTotal, 2),
        ];
    }

    /**
     * payment_plan = installment uses the course's active payment rules as WEIGHTS, then
     * distribute()s the item's actual net_price (after combo/voucher discounts) across them
     * in that proportion - so installments always add up to exactly what the student owes,
     * even though the rules were written against the course's undiscounted price. No active
     * rules (or payment_plan = full) -> a single "Full payment" row for the whole net_price,
     * so later steps (Payment API) only ever deal with "a list of dues", never a special case.
     */
    private function installmentsFor(CoursePrice $price, float $netPrice, PaymentPlan $plan): array
    {
        $rules = $plan === PaymentPlan::Installment
            ? $price->paymentRules()->where('is_active', true)->get()
            : collect();

        if ($rules->isEmpty()) {
            return [
                [
                    'rule' => null,
                    'sequence' => 1,
                    'title' => 'Full payment',
                    'amount' => $netPrice,
                    'due_before_module_id' => null,
                ]
            ];
        }

        $weights = $rules->map(fn($rule) => $rule->amountFor($netPrice))->all();
        $amounts = $this->distribute($netPrice, $weights);

        return $rules->values()->map(fn($rule, $i) => [
            'rule' => $rule,
            'sequence' => $i + 1,
            'title' => $rule->rule_name,
            'amount' => $amounts[$i],
            'due_before_module_id' => $rule->course_modules_id,
        ])->all();
    }

    private function persist(Student $student, array $priced): Order
    {
        $total = $priced['total'];
        $isFree = $total <= 0.0;

        $attribution = AffiliateAttribution::open()->where('student_id', $student->id)->first();

        $order = Order::create([
            'order_no' => $this->nextOrderNo(),
            'student_id' => $student->id,
            'affiliate_attribution_id' => $attribution?->id,
            'payment_combo_id' => $priced['combo']?->id,
            'payment_voucher_id' => $priced['voucher']?->id,
            'status' => $isFree ? OrderStatus::Paid : OrderStatus::Pending,
            'subtotal' => $priced['subtotal'],
            'combo_discount' => $priced['combo_discount'],
            'voucher_discount' => $priced['voucher_discount'],
            'total' => $total,
            'paid_amount' => 0,
            'expires_at' => $isFree ? null : now()->addHours(Order::PAYMENT_WINDOW_HOURS),
            'completed_at' => $isFree ? now() : null,
        ]);

        foreach ($priced['items'] as $item) {
            // Nothing to collect for this course: the whole order is free (100% voucher), or this
            // one course is free (a 0-price course bought alongside paid ones). Its dues are born
            // paid - the state PaymentService::verify() would otherwise leave them in - so it is
            // enrolled now. Without this, a 0-amount due could never be paid (a payment must be
            // at least 0.01) and the free course would never unlock.
            $itemFree = $isFree || (float) $item['net_price'] <= 0.0;

            /** @var OrderItem $orderItem */
            $orderItem = $order->items()->create([
                'course_list_id' => $item['course']->id,
                'batch_list_id' => $item['batch']->id,
                'course_prices_id' => $item['course_price_id'],
                'payment_plan' => $item['payment_plan'],
                'list_price' => $item['list_price'],
                'sale_price' => $item['sale_price'],
                'combo_discount' => $item['combo_discount'],
                'voucher_discount' => $item['voucher_discount'],
                'net_price' => $item['net_price'],
                'status' => OrderItemStatus::Active,
            ]);

            foreach ($item['installments'] as $inst) {
                $orderItem->installments()->create([
                    'course_payment_rule_id' => $inst['rule']?->id,
                    'sequence' => $inst['sequence'],
                    'title' => $inst['title'],
                    'amount' => $inst['amount'],
                    'due_before_module_id' => $inst['due_before_module_id'],
                    'status' => $itemFree ? InstallmentStatus::Paid : InstallmentStatus::Unpaid,
                    'paid_at' => $itemFree ? now() : null,
                ]);
            }

            if ($itemFree) {
                $this->enrollment->syncItemState($orderItem->load('installments'), $order);
            }
        }

        if ($priced['voucher']) {
            $priced['voucher']->update(['status' => VoucherStatus::Used, 'used_at' => now()]);
        }

        return $order->load(['items.course', 'items.batch', 'items.installments']);
    }

    private function nextOrderNo(): string
    {
        $prefix = 'SF-' . now()->format('ym') . '-';
        $seq = Order::where('order_no', 'like', $prefix . '%')->count() + 1;

        do {
            $no = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Order::where('order_no', $no)->exists());

        return $no;
    }

    /** For the quote endpoint: the same numbers, shaped for JSON instead of for persist(). */
    private function present(array $priced): array
    {
        return [
            'items' => collect($priced['items'])->map(fn($item) => [
                'course_id' => $item['course']->id,
                'course_title' => $item['course']->title,
                'batch_id' => $item['batch']->id,
                'batch_name' => $item['batch']->name,
                'payment_plan' => $item['payment_plan']->value,
                'list_price' => $item['list_price'],
                'sale_price' => $item['sale_price'],
                'combo_discount' => $item['combo_discount'],
                'voucher_discount' => $item['voucher_discount'],
                'net_price' => $item['net_price'],
                'installments' => collect($item['installments'])->map(fn($i) => [
                    'sequence' => $i['sequence'],
                    'title' => $i['title'],
                    'amount' => $i['amount'],
                ])->values(),
            ])->values(),
            'combo' => $priced['combo'] ? ['id' => $priced['combo']->id, 'title' => $priced['combo']->title] : null,
            'voucher' => $priced['voucher'] ? ['code' => $priced['voucher']->code, 'title' => $priced['voucher']->title] : null,
            'subtotal' => $priced['subtotal'],
            'combo_discount' => $priced['combo_discount'],
            'voucher_discount' => $priced['voucher_discount'],
            'total' => $priced['total'],
        ];
    }

    /**
     * Splits $amount across $weights in proportion, in integer cents, so the parts always add
     * up to exactly $amount (never a cent over/under from rounding) - leftover cents go to the
     * largest shares first. Used for combo/voucher discount splits and for installment amounts.
     *
     * @param  float[]  $weights
     * @return float[] same length as $weights
     */
    private function distribute(float $amount, array $weights): array
    {
        $amountCents = (int) round($amount * 100);
        $weightSum = array_sum($weights);

        if ($weightSum <= 0 || $amountCents <= 0) {
            return array_fill(0, count($weights), 0.0);
        }

        $fractions = [];
        $floors = [];
        $flooredTotal = 0;

        foreach (array_values($weights) as $i => $w) {
            $share = $amountCents * $w / $weightSum;
            $floor = (int) floor($share);
            $fractions[$i] = $share - $floor;
            $floors[$i] = $floor;
            $flooredTotal += $floor;
        }

        $remainder = $amountCents - $flooredTotal;
        arsort($fractions);
        foreach (array_keys($fractions) as $i) {
            if ($remainder <= 0) {
                break;
            }
            $floors[$i]++;
            $remainder--;
        }

        ksort($floors);

        return array_map(fn($cents) => round($cents / 100, 2), $floors);
    }
}
