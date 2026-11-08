<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Small card for the combo list. @mixin \App\Models\PaymentCombo */
class ComboCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail' => $this->thumbnail_url,
            'course_count' => $this->courses->count(),
            'courses' => $this->courses->map(fn($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug,
                //'thumbnail' => $c->thumbnail_url,
            ])->values(),
            'price' => [
                'courses_total' => $this->coursesTotal(),
                'final' => $this->finalPrice(),
                'you_save' => $this->discountAmount(),
            ],
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }
}
