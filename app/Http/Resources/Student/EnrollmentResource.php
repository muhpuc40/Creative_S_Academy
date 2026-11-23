<?php

namespace App\Http\Resources\Student;

use App\Enums\InstallmentStatus;
use App\Models\OrderItemInstallment;
use App\Services\Enrollment\RenewalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One course in the student's "My courses". @mixin \App\Models\Enrollment */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->orderItem;
        $installments = $item?->installments ?? collect();
        // The first due that isn't paid yet (it may already be awaiting verification).
        $next = $installments->first(fn (OrderItemInstallment $i) => ! in_array($i->status, [InstallmentStatus::Paid, InstallmentStatus::Cancelled], true));

        $renewals = app(RenewalService::class);
        $renewal = $renewals->eligibility($this->resource);
        $renewalPrice = $renewals->priceFor($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'progress_percent' => (float) $this->progress_percent,
            'completed_at' => $this->completed_at?->toIso8601String(),
            // videos, recordings and resources lock after this (null = never)
            'access_expires_at' => $this->access_expires_at?->toIso8601String(),
            'renewal' => [
                'can_renew' => $renewal === RenewalService::OK,
                'reason' => $renewal === RenewalService::OK ? null : $renewal,   // not_offered | not_needed | too_early | pending | not_enrolled
                'opens_at' => $renewals->opensAt($this->resource)?->toIso8601String(),
                'fee' => $renewalPrice ? (float) $renewalPrice->renewal_fee : null,
                'days' => $renewalPrice?->renewal_days,
                'pending_order_no' => $renewal === RenewalService::PENDING ? $renewals->pendingOrder($this->resource)?->order_no : null,
            ],
            'course' => [
                'id' => $this->course_list_id,
                'title' => $this->course?->title,
                'slug' => $this->course?->slug,
                'thumbnail' => $this->course?->thumbnail_url,
            ],
            'batch' => [
                'id' => $this->batch_list_id,
                'name' => $this->batch?->name,
                'start_date' => $this->batch?->start_date?->toDateString(),
            ],
            'order_id' => $item?->order_list_id,
            'order_no' => $item?->order?->order_no,
            'payment_plan' => $item?->payment_plan->value,
            'dues' => [
                'paid' => $installments->where('status', InstallmentStatus::Paid)->count(),
                'total' => $installments->count(),
                'next' => $next ? [
                    'id' => $next->id,
                    'title' => $next->title,
                    'amount' => $next->amount,
                    'status' => $next->status->value,
                ] : null,
            ],
        ];
    }
}
