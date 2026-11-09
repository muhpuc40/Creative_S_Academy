<?php

namespace App\Http\Controllers\Admin\Batch;

use App\Enums\BatchStatus;
use App\Http\Controllers\Admin\Batch\Concerns\ManagesBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchRequest;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseContent;
use App\Models\Instructor;
use App\Services\Google\GoogleMeet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * GET /batches            cards (3 per row)
 * GET /batches/create     new batch
 * GET /batches/{id}       details: overview, classes, exams, assignments
 */
class BatchController extends Controller
{
    use ManagesBatch;

    public function index(Request $request): View
    {
        $batches = Batch::query()
            ->with(['course:id,title,thumbnail', 'instructor:id,name'])
            ->withCount(['classes', 'exams', 'assignments'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->input('q').'%')
                ->orWhere('code', 'like', '%'.$request->input('q').'%')))
            ->when($request->filled('course'), fn ($q) => $q->where('course_list_id', $request->input('course')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(9)
            ->withQueryString();

        return view('admin.batches.index', [
            'batches' => $batches,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'statuses' => BatchStatus::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.batches.create', $this->formData(new Batch(['start_date' => now()])));
    }

    public function store(BatchRequest $request): RedirectResponse
    {
        $data = $this->prepare($request->validated());
        $data['code'] = $data['code'] ?: $this->makeCode(Course::findOrFail($data['course_list_id']));

        $batch = Batch::create($data);

        return redirect()->route('admin.batches.show', $batch)->with('success', 'Batch created. Now add its class schedule.');
    }

    public function show(Batch $batch, GoogleMeet $meet): View
    {
        $batch->load([
            'course:id,title,thumbnail',
            'instructor:id,name',
            'classes.item.module', 'classes.item.content:id,name',
            'classes.instructor:id,name', 'classes.liveInfo.recording:id,name',
            'exams.item.module', 'exams.item.content:id,name',
            'assignments.item.module', 'assignments.item.content:id,name',
        ]);

        $options = $this->itemOptions($batch);

        // item id => type, so the class form can show the live link fields only for live items
        $itemTypes = [];
        foreach ($options as $type => $list) {
            foreach ($list as $item) {
                $itemTypes[(string) $item['id']] = $type;
            }
        }

        return view('admin.batches.show', [
            'batch' => $batch,
            'itemOptions' => $options,
            'itemTypes' => $itemTypes,
            'instructors' => Instructor::orderBy('name')->get(['id', 'name']),
            'contents' => CourseContent::orderBy('name')->get(['id', 'name']),
            'meetReady' => $meet->ready(), // "Generate" Google Meet link in the class form
        ]);
    }

    public function edit(Batch $batch): View
    {
        return view('admin.batches.edit', $this->formData($batch));
    }

    public function update(BatchRequest $request, Batch $batch): RedirectResponse
    {
        $data = $this->prepare($request->validated());
        $data['code'] = $data['code'] ?: $batch->code;

        // A batch that already has classes / exams / assignments keeps its course (they belong to its items).
        if ($this->hasSchedule($batch)) {
            $data['course_list_id'] = $batch->course_list_id;
        }

        $batch->update($data);

        return redirect()->route('admin.batches.show', $batch)->with('success', 'Batch updated.');
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        if ($this->hasSchedule($batch)) {
            return back()->with('error', 'This batch has classes, exams or assignments. Delete them first, or set the batch status to Cancelled.');
        }

        $batch->delete();

        return redirect()->route('admin.batches.index')->with('success', 'Batch deleted.');
    }

    private function hasSchedule(Batch $batch): bool
    {
        return $batch->classes()->exists() || $batch->exams()->exists() || $batch->assignments()->exists();
    }

    private function formData(Batch $batch): array
    {
        return [
            'batch' => $batch,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'instructors' => Instructor::orderBy('name')->get(['id', 'name']),
            'statuses' => BatchStatus::cases(),
            'courseLocked' => $batch->exists && $this->hasSchedule($batch),
        ];
    }

    private function prepare(array $data): array
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['code'] = isset($data['code']) ? Str::upper($data['code']) : null;

        return $data;
    }

    /** FSW-1, FSW-2 ... made from the first letters of the course title. */
    private function makeCode(Course $course): string
    {
        $letters = collect(preg_split('/\s+/', trim($course->title)))
            ->filter()
            ->map(fn ($word) => Str::upper(Str::substr($word, 0, 1)))
            ->take(4)
            ->implode('');

        $prefix = preg_match('/^[A-Z0-9]+$/', $letters) ? $letters : 'B';
        $number = Batch::withTrashed()->where('course_list_id', $course->id)->count() + 1;

        do {
            $code = $prefix.'-'.$number++;
        } while (Batch::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
