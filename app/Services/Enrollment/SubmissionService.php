<?php

namespace App\Services\Enrollment;

use App\Enums\AssignmentStatus;
use App\Enums\ExamStatus;
use App\Models\BatchAssignment;
use App\Models\BatchExam;
use App\Models\Enrollment;
use App\Models\EnrollmentResult;
use App\Models\Student;
use App\Support\PrivateFile;
use App\Support\Security\SecurityScanner;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A student's assignment and exam submissions: one file each, saved in private storage
 * (submissions/batch-{id}/...) and recorded in enrolled_user_result. No pass or fail.
 *
 *   assignment  open from assigned_at to due_at. After due_at only if allow_late (marked late).
 *               The file can be replaced until the deadline, as long as it isn't marked yet.
 *   exam        the answer file is accepted from the start until start + duration + 10 minutes
 *               of grace for uploading. Once only.
 * Submitting counts the item as done in progress.
 */
class SubmissionService
{
    public const EXAM_GRACE_MINUTES = 10;

    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const NOT_OPEN = 'not_open';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';
    public const ALREADY_SUBMITTED = 'already_submitted';
    public const GRADED = 'graded';
    public const UNSAFE_FILE = 'unsafe_file';

    public function __construct(
        private AccessService $access,
        private ProgressService $progress,
        private SecurityScanner $scanner,
    ) {}

    /** The student's active enrollment in the batch of an assignment or exam. */
    public function enrollmentFor(Student $student, BatchAssignment|BatchExam $work): ?Enrollment
    {
        return Enrollment::active()
            ->where('student_id', $student->id)
            ->where('batch_list_id', $work->batch_list_id)
            ->latest('id')
            ->first();
    }

    public function resultFor(Enrollment $enrollment, BatchAssignment|BatchExam $work): ?EnrollmentResult
    {
        return EnrollmentResult::where('enrollment_id', $enrollment->id)
            ->where($work instanceof BatchExam ? 'batchwise_exam_id' : 'batchwise_assignment_id', $work->id)
            ->first();
    }

    /** When answers stop being accepted: the end of the exam + the upload grace. */
    public function examClosesAt(BatchExam $exam): CarbonInterface
    {
        return $exam->endsAt()->addMinutes(self::EXAM_GRACE_MINUTES);
    }

    /** Can the student see this at all? not_found | an AccessService reason | ok */
    public function visibility(?Enrollment $enrollment, BatchAssignment|BatchExam $work): string
    {
        if ($work instanceof BatchAssignment && $work->status === AssignmentStatus::Draft) {
            return self::NOT_FOUND;   // not given out yet
        }

        if (!$enrollment) {
            return AccessService::NOT_ENROLLED;
        }

        $work->loadMissing('item');
        if (!$work->item) {
            return self::NOT_FOUND;
        }

        $access = $this->access->check($enrollment, $work->item);

        return $access === AccessService::INACTIVE && $work instanceof BatchExam && $work->status === ExamStatus::Cancelled
            ? self::CANCELLED   // a cancelled exam: say so rather than "not available"
            : $access;
    }

    /** May the student submit (or replace) now? ok or the reason. */
    public function canSubmit(?Enrollment $enrollment, BatchAssignment|BatchExam $work): string
    {
        $visible = $this->visibility($enrollment, $work);
        if ($visible !== AccessService::OK) {
            return $visible;
        }

        return $this->timing($work, $this->resultFor($enrollment, $work));
    }

    /**
     * The time / state part of canSubmit(), for work the student may see:
     * ok | cancelled | not_open | closed | already_submitted | graded. (CourseMap uses it too.)
     */
    public function timing(BatchAssignment|BatchExam $work, ?EnrollmentResult $existing): string
    {
        if ($work instanceof BatchExam) {
            if ($work->status === ExamStatus::Cancelled) {
                return self::CANCELLED;
            }
            if (now()->lt($work->startsAt())) {
                return self::NOT_OPEN;
            }
            if (now()->gt($this->examClosesAt($work))) {
                return self::CLOSED;
            }

            return $existing?->submitted_at ? self::ALREADY_SUBMITTED : self::OK;
        }

        if ($work->status === AssignmentStatus::Closed) {
            return self::CLOSED;
        }
        if (now()->lt($work->assigned_at)) {
            return self::NOT_OPEN;
        }
        if (now()->gt($work->due_at) && !$work->allow_late) {
            return self::CLOSED;
        }
        if ($existing && $existing->status === EnrollmentResult::STATUS_GRADED) {
            return self::GRADED;   // already marked: no more changes
        }

        return self::OK;
    }

    /**
     * Saves the file (call only after canSubmit() said OK). A replaced assignment file is deleted.
     * Returns the result row, or UNSAFE_FILE if the security check flags the upload.
     */
    public function submit(Enrollment $enrollment, BatchAssignment|BatchExam $work, UploadedFile $file): EnrollmentResult|string
    {
        $isExam = $work instanceof BatchExam;
        $path = PrivateFile::store($file, "submissions/batch-{$work->batch_list_id}/".($isExam ? "exam-{$work->id}" : "assignment-{$work->id}"));

        // the same check the security scan runs: hidden double extensions, code inside the file
        if ($this->scanner->inspect(Storage::disk(PrivateFile::DISK)->path($path))) {
            PrivateFile::delete($path);

            return self::UNSAFE_FILE;
        }

        try {
            return DB::transaction(function () use ($enrollment, $work, $file, $path, $isExam) {
                Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();   // one submission at a time

                // checked again inside the lock: two uploads at once can't both pass "once only"
                $reason = $this->canSubmit($enrollment, $work);
                if ($reason !== self::OK) {
                    throw new SubmissionRefused($reason);
                }

                $result = $this->resultFor($enrollment, $work) ?? new EnrollmentResult([
                    'enrollment_id' => $enrollment->id,
                    'type' => $isExam ? EnrollmentResult::TYPE_EXAM : EnrollmentResult::TYPE_ASSIGNMENT,
                    $isExam ? 'batchwise_exam_id' : 'batchwise_assignment_id' => $work->id,
                    'course_module_items_id' => $work->course_module_items_id,
                ]);
                $old = $result->file_path;

                $result->fill([
                    'file_path' => $path,
                    'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'file_size' => $file->getSize(),
                    'submitted_at' => now(),
                    'is_late' => !$isExam && now()->gt($work->due_at),
                    'status' => EnrollmentResult::STATUS_SUBMITTED,
                ])->save();

                $this->progress->complete($enrollment, $work->item, 'submitted');

                if ($old && $old !== $path) {
                    DB::afterCommit(fn () => PrivateFile::delete($old));
                }

                return $result;
            });
        } catch (SubmissionRefused $refused) {
            PrivateFile::delete($path);

            return $refused->reason;
        }
    }
}
