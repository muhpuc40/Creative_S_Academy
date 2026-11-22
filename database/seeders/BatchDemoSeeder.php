<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseContent;
use App\Models\CourseModuleItem;
use App\Models\Instructor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Batches, class schedule, exams and assignments (run CourseDemoSeeder first).
 *   php artisan db:seed --class=BatchDemoSeeder
 *
 * One batch per course (d = days from today):
 *  full-stack-web-development  running, no deadline -> always open (has held classes)
 *  flutter-app-development     upcoming, deadline in 10 days, 20 seats
 *  python-for-data-science     upcoming, no seat limit
 */
class BatchDemoSeeder extends Seeder
{
    private array $instructors = [];
    /** next number for each code prefix */
    private array $codeCount = [];

    public function run(): void
    {
        if (Batch::withTrashed()->exists() || ! Course::exists()) {
            return;
        }

        $this->instructors = Instructor::orderBy('id')->pluck('id')->all();
        if (! $this->instructors) {
            $this->instructors = [Instructor::create(['name' => 'Demo Instructor', 'email' => 'demo.instructor@example.com', 'password' => 'Password@123'])->id];
        }

        // slug => [ [status, start d, end d, deadline d|null, extended d|null, seats|null, extra] ... ]
        $plan = [
            'full-stack-web-development' => [['running', -10, 120, null, null, 40, []]],
            'flutter-app-development' => [['upcoming', 15, 120, 10, null, 20, []]],
            'python-for-data-science' => [['upcoming', 21, 110, 14, null, null, []]],
        ];

        foreach ($plan as $slug => $batches) {
            $course = Course::where('slug', $slug)->first();
            if (! $course) {
                continue;
            }
            foreach ($batches as $i => [$status, $start, $end, $deadline, $extended, $seats, $extra]) {
                $batch = Batch::create([
                    'course_list_id' => $course->id,
                    'name' => 'Batch '.($i + 1),
                    'code' => $this->code($course),
                    'instructor_id' => $this->instructors[($course->id + $i) % count($this->instructors)],
                    'start_date' => $this->day($start),
                    'approx_end_date' => $this->day($end),
                    'enroll_deadline' => $deadline === null ? null : $this->day($deadline),
                    'extended_enroll_deadline' => $extended === null ? null : $this->day($extended),
                    'seat_limit' => $seats,
                    'status' => $status,
                    'is_active' => $extra['is_active'] ?? true,
                ]);

                if ($status !== 'cancelled') {
                    $this->schedule($batch, $course);
                }
                if (! empty($extra['deleted'])) {
                    $batch->delete();
                }
            }
        }
    }

    private function day(int $days): Carbon
    {
        return now()->addDays($days)->startOfDay();
    }

    /** FSW-1, FSW-2 ... from the course title initials. */
    private function code(Course $course): string
    {
        $prefix = collect(preg_split('/\s+/', preg_replace('/[^A-Za-z ]/', '', $course->title)))
            ->filter()->map(fn ($w) => strtoupper($w[0]))->take(3)->implode('');
        $prefix = str_pad($prefix, 2, 'X');
        $this->codeCount[$prefix] = ($this->codeCount[$prefix] ?? 0) + 1;

        return $prefix.'-'.$this->codeCount[$prefix];
    }

    /** Classes, exams and assignments for the first modules of the course. */
    private function schedule(Batch $batch, Course $course): void
    {
        $moduleIds = $course->modules()->where('course_modules.is_active', true)->orderBy('course_modules.id')->pluck('course_modules.id');
        $items = CourseModuleItem::whereIn('course_modules_id', $moduleIds)->where('is_active', true)->get()->groupBy('course_modules_id');

        $finished = $batch->status->value === 'completed';
        $running = $batch->status->value === 'running';
        $start = Carbon::parse($batch->start_date);
        $count = $finished || $running ? 5 : 2;      // modules scheduled
        $day = 0;
        $platforms = ['zoom', 'meet', 'other'];

        foreach ($moduleIds->take($count) as $m => $moduleId) {
            $moduleItems = $items[$moduleId] ?? collect();
            $live = $moduleItems->firstWhere('item_type.value', 'live');
            $recorded = $moduleItems->firstWhere('item_type.value', 'recorded');
            $exam = $moduleItems->firstWhere('item_type.value', 'exam');
            $assignment = $moduleItems->firstWhere('item_type.value', 'assignment');

            // live class
            if ($live) {
                $time = $start->copy()->addDays($day += 3)->setTime(20, 0);
                $past = $time->isPast();
                $status = $past ? 'held' : 'scheduled';
                if ($running && $m === 3) {
                    $status = 'cancelled';   // one cancelled class
                }
                $class = $batch->classes()->create([
                    'course_module_items_id' => $live->id, 'class_start_time' => $time,
                    'instructor_id' => $batch->instructor_id, 'status' => $status,
                ]);
                $isHeld = $status === 'held';
                $recording = $isHeld ? CourseContent::create([
                    'name' => "Recording: {$batch->code} class ".($m + 1),
                    'url' => "https://example.com/recordings/{$batch->code}-".($m + 1).'.mp4',
                ]) : null;
                $class->liveInfo()->create([
                    'batch_list_id' => $batch->id, 'platform' => $platforms[$m % 3],
                    'meeting_url' => 'https://meet.google.com/demo-'.strtolower($batch->code).'-'.$m,
                    'is_started' => $isHeld, 'recording_content_id' => $recording?->id,
                ]);
            }

            // recorded class (no live link)
            if ($recorded) {
                $batch->classes()->create([
                    'course_module_items_id' => $recorded->id, 'class_start_time' => $start->copy()->addDays($day += 1)->setTime(9, 0),
                    'duration_minutes' => 60, 'status' => $start->copy()->addDays($day)->isPast() ? 'held' : 'scheduled',
                ]);
            }

            // exam
            if ($exam) {
                $date = $start->copy()->addDays($day += 2);
                $over = $date->isPast();
                $batch->exams()->create([
                    'course_module_items_id' => $exam->id, 'title' => 'Exam: '.$exam->objective,
                    'exam_date' => $date, 'start_time' => '10:00', 'duration_minutes' => 60, 'total_marks' => 50, 'pass_marks' => 20,
                    'instruction' => 'Bring your own laptop. No mobile phone allowed.',
                    'status' => $over ? 'finished' : 'scheduled',
                    'result_published_at' => $over && $m % 2 === 0 ? $date->copy()->addDays(3) : null,   // some results not published yet
                ]);
            }

            // assignment
            if ($assignment) {
                $assigned = $start->copy()->addDays($day)->setTime(12, 0);
                $due = $assigned->copy()->addDays(5);
                $batch->assignments()->create([
                    'course_module_items_id' => $assignment->id, 'title' => 'Assignment: '.$assignment->objective,
                    'instruction' => 'Upload your work as a zip file or a GitHub link.',
                    'assigned_at' => $assigned, 'due_at' => $due, 'total_marks' => 20,
                    'allow_late' => $m % 2 === 1,
                    'status' => $due->isPast() ? 'closed' : ($assigned->isPast() || $m === 0 ? 'published' : 'draft'),
                ]);
            }
        }
    }
}
