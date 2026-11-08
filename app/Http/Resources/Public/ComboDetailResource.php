<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full combo details page. @mixin \App\Models\PaymentCombo */
class ComboDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail' => $this->thumbnail_url,
            // Each course as its own card (category, its own price, next open batch) so the
            // combo page can show what's inside without a second round of requests.
            'courses' => CourseCardResource::collection($this->courses)->resolve(),
            'price' => [
                'courses_total' => $this->coursesTotal(),
                'final' => $this->finalPrice(),
                'you_save' => $this->discountAmount(),
            ],
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }
}
