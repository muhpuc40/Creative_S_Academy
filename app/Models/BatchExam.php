<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BatchExam extends Model
{
    use SoftDeletes;

    protected $table = 'batchwise_exam';
    protected $guarded = ['id'];

    /** Same defaults as the database, so a new model is correct before it is reloaded. */
    protected $attributes = ['status' => 'scheduled', 'pass_marks' => 0];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'duration_minutes' => 'integer',
            'total_marks' => 'integer',
            'pass_marks' => 'integer',
            'result_published_at' => 'datetime',
            'status' => ExamStatus::class,
        ];
    }

    /** Link of the uploaded exam paper (or null). The file is private: admins only. */
    protected function examPaperUrl(): Attribute
    {
        return Attribute::get(fn() => $this->exam_paper ? route('admin.batch-exams.paper', $this) : null);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_list_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseModuleItem::class, 'course_module_items_id');
    }

    public function isResultPublished(): bool
    {
        return $this->result_published_at !== null;
    }
}
