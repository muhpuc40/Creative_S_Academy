<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Public\CategoryResource;
use App\Models\CourseCategory;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories : every category that has published courses (by name),
     * each with its published courses as course cards (newest first).
     * The website builds all its course lists from this one call.
     */
    public function index(): JsonResponse
    {
        $categories = CourseCategory::query()
            ->whereHas('courses', fn ($q) => $q->published())
            ->with(['courses' => fn ($q) => $q->published()->with(['currentPrice', 'openBatch'])->latest('id')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon']);

        // each course card shows its category: it is the parent, no need to load it again
        foreach ($categories as $category) {
            $category->courses->each->setRelation('category', $category);
        }

        return response()->json(['data' => CategoryResource::collection($categories)->resolve()]);
    }
}
