<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\ComboCardResource;
use App\Http\Resources\Public\ComboDetailResource;
use App\Models\PaymentCombo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    /**
     * GET /api/v1/combos            every combo a student can buy right now
     * Optional: ?per_page=12 (max 50)  ?page=2
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $combos = $this->visible()
            ->with('courses.currentPrice')
            ->latest('id')
            ->paginate($data['per_page'] ?? 12);

        return response()->json([
            'data' => ComboCardResource::collection($combos)->resolve(),
        ]);
    }

    /** GET /api/v1/combos/{slug} : full details of one combo a student can buy right now. */
    public function show(string $combo): JsonResponse
    {
        $combo = $this->visible()
            ->with(['courses.currentPrice', 'courses.category:id,name,slug', 'courses.openBatch'])
            ->where('slug', $combo)
            ->firstOr(fn() => abort(404, 'Combo not found.'));

        return response()->json(['data' => (new ComboDetailResource($combo))->resolve()]);
    }

    /**
     * Admin turned it on, the deadline hasn't passed, it has started (if it has a start date) -
     * AND every course inside it is still published and priced. A combo is never shown
     * (or sold) with a course that isn't itself publicly buyable, since the price is always
     * calculated live from the courses' current price.
     */
    private function visible(): Builder
    {
        return PaymentCombo::query()
            ->active()
            ->whereDoesntHave('courses', function (Builder $q) {
                $q->where(fn(Builder $qq) => $qq->where('status', '!=', CourseStatus::Published)->orWhere('is_active', false))
                    ->orWhereDoesntHave('currentPrice');
            });
    }
}
