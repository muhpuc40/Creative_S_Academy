<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CoursePrice */
class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'actual' => (float) $this->actual_price,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => (float) $this->discount_value,
            'final' => $this->finalPrice(),
            'access_days' => (int) $this->access_days,
            'renewal' => $this->offersRenewal() ? ['fee' => (float) $this->renewal_fee, 'days' => (int) $this->renewal_days] : null,
        ];
    }
}
