<?php

namespace App\Http\Controllers\Admin\Batch;

use App\Enums\ExamStatus;
use App\Enums\ItemType;
use App\Http\Controllers\Admin\Batch\Concerns\ManagesBatch;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchExam;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BatchExamController extends Controller
{
    use ManagesBatch;

    public function store(Request $request, Batch $batch): RedirectResponse
    {
        $data = $this->validated($request, $batch);

        if ($request->hasFile('exam_paper')) {
            $data['exam_paper'] = PrivateFile::store($request->file('exam_paper'), 'batch-exam-papers');
        }

        $batch->exams()->create($data);

        return $this->toBatch($batch, 'exams', 'Exam added.');
    }

    public function update(Request $request, BatchExam $exam): RedirectResponse
    {
        $data = $this->validated($request, $exam->batch, $exam);

        if ($request->hasFile('exam_paper')) {
            PrivateFile::delete($exam->exam_paper);
            $data['exam_paper'] = PrivateFile::store($request->file('exam_paper'), 'batch-exam-papers');
        }
        // No new file uploaded: keep the current one (same behaviour as the content/resource library).

        $exam->update($data);

        return $this->toBatch($exam->batch_list_id, 'exams', 'Exam updated.');
    }

    /** Show or hide the result to students. */
    public function result(BatchExam $exam): RedirectResponse
    {
        $published = $exam->result_published_at === null;

        $exam->update(['result_published_at' => $published ? now() : null]);

        return $this->toBatch($exam->batch_list_id, 'exams', $published ? 'Result published.' : 'Result hidden again.');
    }

    /** GET batch-exams/{exam}/paper: opens the exam paper (private, admins only). */
    public function paper(BatchExam $exam): BinaryFileResponse
    {
        return PrivateFile::response($exam->exam_paper, $exam->title);
    }

    public function destroy(BatchExam $exam): RedirectResponse
    {
        $batchId = $exam->batch_list_id;

        PrivateFile::delete($exam->exam_paper);
        $exam->delete();

        return $this->toBatch($batchId, 'exams', 'Exam deleted.');
    }

    private function validated(Request $request, Batch $batch, ?BatchExam $exam = null): array
    {
        $data = $request->validate([
            'course_module_items_id' => [
                'required',
                'integer',
                Rule::exists('course_module_items', 'id')->where(
                    fn($q) => $q->where('item_type', ItemType::Exam->value)->whereIn('course_modules_id', $this->moduleIds($batch))
                ),
                Rule::unique('batchwise_exam', 'course_module_items_id')
                    ->where('batch_list_id', $batch->id)->whereNull('deleted_at')->ignore($exam?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:65535'],
            'pass_marks' => ['nullable', 'integer', 'min:0', 'lte:total_marks'],
            'exam_paper' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'instruction' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(ExamStatus::class)],
        ], [
            'course_module_items_id.exists' => 'Choose an exam item of this batch\'s course.',
            'course_module_items_id.unique' => 'This exam is already scheduled in this batch.',
            'pass_marks.lte' => 'Pass marks cannot be more than the total marks.',
            'exam_paper.mimes' => 'The exam paper must be a PDF file.',
            'exam_paper.max' => 'The exam paper must not be larger than 10 MB.',
        ]);

        $data['pass_marks'] ??= 0;
        unset($data['exam_paper']);   // handled separately in store()/update()

        return $data;
    }
}