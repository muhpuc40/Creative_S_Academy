<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One slide of the home page hero, in the shape the website expects. @mixin \App\Models\HeroContent */
class HeroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image' => $this->image_url,
            'button_text' => $this->button_text,
            'button_link' => $this->button_path,
            'bg_color' => $this->bg_color,
            'bg_image' => $this->bg_image_url,
        ];
    }
}
