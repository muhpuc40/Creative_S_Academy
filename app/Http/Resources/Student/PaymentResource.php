<?php

namespace App\Http\Resources\Student;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A student's own payment submission. @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_list_id,
            'order_no' => $this->order?->order_no,
            'method' => $this->method->value,
            'provider' => $this->provider,
            'sender_account' => $this->sender_account,
            'trx_id' => $this->trx_id,
            'proof_image' => Media::url($this->proof_image),
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'reject_reason' => $this->reject_reason,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'installments' => $this->installments->map(fn ($inst) => [
                'id' => $inst->id,
                'course_title' => $inst->item?->course?->title,
                'sequence' => $inst->sequence,
                'title' => $inst->title,
                'amount' => $inst->amount,
            ])->values(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
