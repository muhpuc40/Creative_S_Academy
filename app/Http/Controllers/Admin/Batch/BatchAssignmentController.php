<?php

namespace App\Http\Controllers\Admin\Batch;

use App\Enums\AssignmentStatus;
use App\Enums\ItemType;
use App\Http\Controllers\Admin\Batch\Concerns\ManagesBatch;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchAssignment;
use App\Support\FinishedUpload;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BatchAssignmentController extends Controller
{
    use ManagesBatch;

    public function store(Request $request, Batch $batch): RedirectResponse
    {
        $data = $this->validated($request, $batch);

        DB::transaction(function () use ($request, $batch, $data) {
            if ($request->filled('chunkit_upload_id')) {
                $data['assignment_file'] = $this->publish($request->input('chunkit_upload_id'));
            }

            $batch->assignments()->create($data);
        });

        return $this->toBatch($batch, 'assignments', 'Assignment added.');
    }

    public function update(Request $request, BatchAssignment $assignment): RedirectResponse
    {
        $data = $this->validated($request, $assignment->batch, $assignment);
        $old = $assignment->assignment_file;

        DB::transaction(function () use ($request, $assignment, &$data) {
            if ($request->filled('chunkit_upload_id')) {
                $data['assignment_file'] = $this->publish($request->input('chunkit_upload_id'));
            }
            // No new file uploaded: keep the current one.

            $assignment->update($data);
        });

        if (isset($data['assignment_file'])) {
            PrivateFile::delete($old); // replaced
        }

        return $this->toBatch($assignment->batch_list_id, 'assignments', 'Assignment updated.');
    }

    public function destroy(BatchAssignment $assignment): RedirectResponse
    {
        $batchId = $assignment->batch_list_id;

        PrivateFile::delete($assignment->assignment_file);
        $assignment->delete();

        return $this->toBatch($batchId, 'assignments', 'Assignment deleted.');
    }

    /** GET batch-assignments/{assignment}/file: opens the assignment file (private, admins only). */
    public function file(BatchAssignment $assignment): BinaryFileResponse
    {
        return PrivateFile::response($assignment->assignment_file, $assignment->title);
    }

    /**
     * The assignment file is uploaded in chunks to chunkit; the form sends the finished upload's id.
     * It must pass System > File types, then it is stored privately. Returns its path.
     */
    private function publish(string $uploadId): string
    {
        $file = FinishedUpload::take($uploadId);
        $file->checkFileType();

        return $file->storeIn('batch-assignment-files');
    }

    private function validated(Request $request, Batch $batch, ?BatchAssignment $assignment = null): array
    {
        $data = $request->validate([
            'course_module_items_id' => [
                'required',
                'integer',
                Rule::exists('course_module_items', 'id')->where(
                    fn($q) => $q->where('item_type', ItemType::Assignment->value)->whereIn('course_modules_id', $this->moduleIds($batch))
                ),
                Rule::unique('batchwise_assignment', 'course_module_items_id')
                    ->where('batch_list_id', $batch->id)->whereNull('deleted_at')->ignore($assignment?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'instruction' => ['nullable', 'string', 'max:10000'],
            'assigned_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after:assigned_at'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:65535'],
            'chunkit_upload_id' => ['nullable', 'uuid'],
            'status' => ['required', Rule::enum(AssignmentStatus::class)],
        ], [
            'course_module_items_id.exists' => 'Choose an assignment item of this batch\'s course.',
            'course_module_items_id.unique' => 'This assignment is already given in this batch.',
            'due_at.after' => 'The due date must be after the assigned date.',
        ]);

        $data['allow_late'] = $request->boolean('allow_late');
        unset($data['chunkit_upload_id']);

        return $data;
    }
}