<?php

namespace App\Http\Resources\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A student's own order. @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'combo_discount' => $this->combo_discount,
            'voucher_discount' => $this->voucher_discount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->dueAmount(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => $this->items->map(fn ($item) => [
                'course_id' => $item->course_list_id,
                'course_title' => $item->course?->title,
                'batch_id' => $item->batch_list_id,
                'batch_name' => $item->batch?->name,
                'payment_plan' => $item->payment_plan->value,
                'list_price' => $item->list_price,
                'sale_price' => $item->sale_price,
                'combo_discount' => $item->combo_discount,
                'voucher_discount' => $item->voucher_discount,
                'net_price' => $item->net_price,
                'status' => $item->status->value,
                'installments' => $item->installments->map(fn ($inst) => [
                    'id' => $inst->id,
                    'sequence' => $inst->sequence,
                    'title' => $inst->title,
                    'amount' => $inst->amount,
                    'status' => $inst->status->value,
                    'due_before_module_id' => $inst->due_before_module_id,
                    'paid_at' => $inst->paid_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
