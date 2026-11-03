<?php

namespace App\Http\Resources\Public;

use App\Enums\ItemType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public details of one course.
 * Content links are NOT sent, except for items the admin marked as "free preview".
 *
 * @mixin \App\Models\Course
 */
class CourseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $price = $this->currentPrice;
        $final = $price?->finalPrice();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail' => $this->thumbnail_url,
            'preview_video' => \App\Support\Media::url($this->preview_video),
            'level' => $this->level,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,

            'price' => $price ? new PriceResource($price) : null,
            // How the student pays: each installment and when it is due.
            'payment_plan' => $price ? $price->paymentRules->map(fn ($rule) => [
                'name' => $rule->rule_name,
                'amount' => $rule->amountFor($final),
                'due' => $rule->paidBeforeModule ? 'Before: '.$rule->paidBeforeModule->title : 'At enrollment',
            ])->values() : [],

            'upcoming_batches' => BatchResource::collection($this->batches),

            'learning_outcomes' => $this->learningOutcomes->pluck('title')->values(),

            'curriculum' => $this->curricula->map(fn ($stage) => [
                'title' => $stage->title,
                'description' => $stage->description,
                'duration_days' => $stage->duration,
                'modules' => $stage->modules->map(fn ($module) => [
                    'title' => $module->title,
                    'week' => $module->week,
                    'objective' => $module->objective,
                    'items' => $module->items->countBy(fn ($i) => $i->item_type->value)->all(),
                    'previews' => $module->items->where('is_preview', true)
                        ->filter(fn ($i) => $i->item_type === ItemType::Recorded && $i->content)
                        ->map(fn ($i) => [
                            'name' => $i->content->name,
                            'thumbnail' => $i->content->thumbnail_url,
                            'url' => $i->content->previewUrl(), // a private upload gets a 2-hour signed link
                        ])->values(),
                ])->values(),
            ])->values(),

            'faqs' => $this->faqs->map(fn ($f) => ['question' => $f->question, 'answer' => $f->answer])->values(),

            'rating' => ['avg' => $this->rating_avg, 'count' => $this->rating_count],
            'reviews' => $this->latestReviews->map(fn ($r) => [
                'name' => $r->student?->name,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'date' => $r->created_at?->toDateString(),
            ])->values(),
        ];
    }
}
