<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A category with its published courses (as course cards). @mixin \App\Models\CourseCategory */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'courses' => CourseCardResource::collection($this->courses)->resolve(),
        ];
    }
}
