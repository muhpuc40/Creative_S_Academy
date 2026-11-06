<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One popup in the shape the website expects. A corner popup has no text fields. @mixin \App\Models\Popup */
class PopupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $content = $this->kind->hasText()
            ? ['image' => $this->image_url, 'title' => $this->title, 'body' => $this->body, 'button_text' => $this->button_text, 'button_link' => $this->link]
            : ['image' => $this->image_url, 'link' => $this->link];

        return ['id' => $this->id] + $content + [
            'pages' => $this->setting->pages,
            'delay_seconds' => $this->setting->delay_seconds,
            'frequency' => $this->setting->frequency->value,
        ];
    }
}
