<?php

namespace App\Models;

use App\Enums\AmountType;
use App\Enums\CourseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Media;
use Illuminate\Support\Facades\DB;

class Course extends Model
{
    use SoftDeletes;

    protected $table = 'course_list';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /** Link of the uploaded thumbnail (or null). */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn() => Media::url($this->thumbnail));
    }

    /** Courses visible on the public website. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published)->where('is_active', true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_categories_id');
    }

    /** Stages of this course (Beginning, Intermediate ...), in order. */
    public function curricula(): HasMany
    {
        return $this->hasMany(CourseCurriculum::class, 'course_list_id')->orderBy('sort_order');
    }

    /** All modules of all stages. */
    public function modules(): HasManyThrough
    {
        return $this->hasManyThrough(CourseModule::class, CourseCurriculum::class, 'course_list_id', 'course_curriculum_id');
    }
    /** @return HasMany<CoursePrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(CoursePrice::class, 'course_list_id');
    }

    /** The price used for new sales. */
    public function currentPrice(): HasOne
    {
        return $this->hasOne(CoursePrice::class, 'course_list_id')->where('is_active', true)->latestOfMany();
    }

    /** The next batch a student can join (earliest start date). Used by the public API. */
    public function openBatch(): HasOne
    {
        return $this->hasOne(Batch::class, 'course_list_id')
            ->ofMany(['start_date' => 'min', 'id' => 'min'], fn(Builder $q) => $q->openForEnrollment());
    }

    /** @return HasMany<Batch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class, 'course_list_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class, 'course_list_id');
    }

    public function learningOutcomes(): HasMany
    {
        return $this->hasMany(CourseLearningOutcome::class, 'course_list_id')->orderBy('sort_order');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(CourseFaq::class, 'course_list_id')->orderBy('sort_order');
    }

    /** Installments of ALL prices, old and new. For the live plan use currentPrice->paymentRules. */
    public function paymentRules(): HasMany
    {
        return $this->hasMany(CoursePaymentRule::class, 'course_list_id')->orderBy('sort_order');
    }

    /**
     * Change the price: the old row is kept (for students who already enrolled)
     * and a new active row is created. Installment rules are copied to the new
     * price unless $copyRules is false.
     *
     * $access = ['access_days' => 180, 'renewal_fee' => 2000 | null, 'renewal_days' => 180 | null].
     * Leave it null to keep the current price's access and renewal settings.
     */
    public function changePrice(
        float $actualPrice,
        ?AmountType $discountType = null,
        float $discountValue = 0,
        bool $copyRules = true,
        ?array $access = null,
    ): CoursePrice {
        return DB::transaction(function () use ($actualPrice, $discountType, $discountValue, $copyRules, $access) {
            /** @var \App\Models\CoursePrice|null $old */
            $old = $this->prices()->where('is_active', true)->latest('id')->first();

            $access ??= $old ? $old->only(['access_days', 'renewal_fee', 'renewal_days']) : [];
            $renewalFee = $access['renewal_fee'] ?? null;

            $this->prices()->where('is_active', true)->update(['is_active' => false]);
            /** @var \App\Models\CoursePrice $new */
            $new = $this->prices()->create([
                'actual_price' => $actualPrice,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'access_days' => (int) ($access['access_days'] ?? 180),
                'renewal_fee' => $renewalFee,
                'renewal_days' => $renewalFee === null ? null : (int) ($access['renewal_days'] ?? 0),
                'is_active' => true,
            ]);

            if ($copyRules && $old) {
                foreach ($old->paymentRules()->where('is_active', true)->get() as $rule) {
                    $new->paymentRules()->create([
                        'course_list_id' => $this->id,
                        'course_modules_id' => $rule->course_modules_id,
                        'rule_name' => $rule->rule_name,
                        'amount_type' => $rule->amount_type,
                        'amount' => $rule->amount,
                        'sort_order' => $rule->sort_order,
                        'is_active' => true,
                    ]);
                }
            }

            return $new;
        });
    }
}
