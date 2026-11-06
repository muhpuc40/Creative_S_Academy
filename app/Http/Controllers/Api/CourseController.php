<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Public\CourseCardResource;
use App\Http\Resources\Public\CourseDetailResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * GET /api/v1/courses            all published courses
     * GET /api/v1/courses?c_id=1     only one category
     * GET /api/v1/courses?q=react    search by course title
     * Optional: ?per_page=12 (max 50)  ?page=2
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'c_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        // Escape % and _ so they are searched as normal characters.
        $search = isset($data['q']) ? addcslashes(trim($data['q']), '%_\\') : '';

        $courses = Course::query()
            ->published()
            ->when($data['c_id'] ?? null, fn ($q, $id) => $q->where('course_categories_id', $id))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->with(['category:id,name,slug', 'currentPrice', 'openBatch'])
            ->latest('id')
            ->paginate($data['per_page'] ?? 12);

        return response()->json([
            'data' => CourseCardResource::collection($courses)->resolve(),
            'meta' => [
                'page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/courses/{id}  or  /api/v1/courses/{slug} : full details of one published course.
     * The website uses the slug (its page URLs are /courses/{slug}).
     */
    public function show(string $course): JsonResponse
    {
        $course = Course::query()
            ->published()
            ->where(ctype_digit($course) ? 'id' : 'slug', $course)
            ->with([
                'category:id,name,slug',
                'currentPrice.paymentRules' => fn ($q) => $q->where('is_active', true)->with('paidBeforeModule:id,title'),
                'learningOutcomes' => fn ($q) => $q->where('is_active', true),
                'faqs' => fn ($q) => $q->where('is_active', true),
                'curricula' => fn ($q) => $q->where('is_active', true),
                'curricula.modules' => fn ($q) => $q->where('is_active', true),
                'curricula.modules.items' => fn ($q) => $q->where('is_active', true),
                'curricula.modules.items.content:id,name,thumbnail,url',
                'batches' => fn ($q) => $q->openForEnrollment()->with('instructor:id,name,designation,avatar')
                    ->orderBy('start_date')->limit(3),
            ])
            ->firstOr(fn () => abort(404, 'Course not found.'));

        $stats = $course->reviews()->approved()->selectRaw('count(*) as total, avg(rating) as average')->first();
        $course->setAttribute('rating_count', (int) $stats->total);
        $course->setAttribute('rating_avg', round((float) $stats->average, 1));
        $course->setRelation('latestReviews', $course->reviews()->approved()->with('student:id,name')
            ->latest('id')->limit(5)->get(['id', 'student_id', 'rating', 'comment', 'created_at']));

        return response()->json(['data' => (new CourseDetailResource($course))->resolve()]);
    }
}
