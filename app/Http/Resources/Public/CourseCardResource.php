<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Small card for the course list. @mixin \App\Models\Course */
class CourseCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'thumbnail' => $this->thumbnail_url,
            'level' => $this->level,
            'category' => $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]
                : null,
            'price' => $this->currentPrice ? new PriceResource($this->currentPrice) : null,
            // Next batch a student can join. null = no batch open now.
            'batch' => $this->openBatch ? new BatchResource($this->openBatch) : null,
        ];
    }
}
