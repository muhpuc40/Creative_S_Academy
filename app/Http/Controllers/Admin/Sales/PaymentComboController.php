<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentComboRequest;
use App\Models\Course;
use App\Models\PaymentCombo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Media;
use Illuminate\View\View;

class PaymentComboController extends Controller
{
    /** GET /combos */
    public function index(Request $request): View
    {
        $filter = $request->input('status');

        $combos = PaymentCombo::query()
            ->with(['courses.currentPrice'])
            ->withCount('courses')
            ->when($request->filled('q'), fn($q) => $q->where('title', 'like', '%' . $request->input('q') . '%'))
            ->when($filter === 'active', fn($q) => $q->active())
            ->when($filter === 'inactive', fn($q) => $q->where('is_active', false))
            ->when($filter === 'expired', fn($q) => $q->where('ends_at', '<', now()))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.combos.index', ['combos' => $combos, 'filter' => $filter]);
    }

    /** GET /combos/create */
    public function create(): View
    {
        return view('admin.combos.create', [
            'combo' => new PaymentCombo(['price_type' => 'percent', 'is_active' => true]),
            'courses' => $this->pickableCourses(),
            'selected' => [],
        ]);
    }

    /** POST /combos */
    public function store(PaymentComboRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['course_ids', 'thumbnail']);
        $data['slug'] = ($data['slug'] ?? null) ?: $this->uniqueSlug($data['title']);
        $data['is_active'] = $request->boolean('is_active');
        $data['created_by'] = $request->user()->id;

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = Media::store($request->file('thumbnail'), 'combo-thumbnails');
        }

        $combo = PaymentCombo::create($data);
        $combo->courses()->sync($request->input('course_ids'));

        return redirect()->route('admin.combos.index')->with('success', 'Combo offer created.');
    }

    /** GET /combos/{combo}/edit */
    public function edit(PaymentCombo $combo): View
    {
        return view('admin.combos.edit', [
            'combo' => $combo,
            'courses' => $this->pickableCourses($combo),
            'selected' => $combo->courses()->pluck('course_list.id')->all(),
        ]);
    }

    /** PUT /combos/{combo} */
    public function update(PaymentComboRequest $request, PaymentCombo $combo): RedirectResponse
    {
        $data = $request->safe()->except(['course_ids', 'thumbnail']);
        $data['slug'] = ($data['slug'] ?? null) ?: $combo->slug;
        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('thumbnail')) {
            Media::delete($combo->thumbnail);
            $data['thumbnail'] = Media::store($request->file('thumbnail'), 'combo-thumbnails');
        }

        $combo->update($data);
        $combo->courses()->sync($request->input('course_ids'));

        return redirect()->route('admin.combos.index')->with('success', 'Combo offer updated.');
    }

    /** DELETE /combos/{combo} */
    public function destroy(PaymentCombo $combo): RedirectResponse
    {
        if (DB::table('order_list')->where('payment_combo_id', $combo->id)->exists()) {
            return back()->with('error', 'This combo was used in an order and cannot be deleted. Turn it off instead.');
        }

        Media::delete($combo->thumbnail);
        $combo->delete();

        return back()->with('success', 'Combo offer deleted.');
    }

    /**
     * Published, priced courses the admin can pick from. When editing, courses already in the
     * combo stay in the list even if they were unpublished afterwards, so nothing silently drops out.
     */
    private function pickableCourses(?PaymentCombo $combo = null)
    {
        return Course::query()
            ->with('currentPrice')
            ->where(function ($q) use ($combo) {
                $q->published()->whereHas('currentPrice');
                if ($combo) {
                    $q->orWhereIn('id', $combo->courses()->pluck('course_list.id'));
                }
            })
            ->orderBy('title')
            ->get(['id', 'title', 'course_categories_id']);
    }

    /** "new-year-offer", or "new-year-offer-2" when taken (deleted combos keep theirs). */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'combo';
        $slug = $base;
        $i = 2;

        while (PaymentCombo::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
