<?php

namespace App\Http\Controllers\Admin\Website;

use App\Enums\DisplayAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HeroContentRequest;
use App\Models\HeroContent;
use App\Models\HeroSetting;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Website > Hero content: the slides of the home page hero.
 * Every slide marked "Show" is in the slider, in sort_order. Whether the website shows these
 * slides or the course list is chosen on Website > Hero settings.
 */
class HeroContentController extends Controller
{
    private const DIRECTORY = 'hero';

    public function index(): View
    {
        return view('admin.website.hero.index', [
            'heroes' => HeroContent::ordered()->get(),
            'sliderIsOn' => HeroSetting::isOn(DisplayAction::HeroContent),
        ]);
    }

    public function store(HeroContentRequest $request): RedirectResponse
    {
        $data = $request->hero() + ['image' => Media::store($request->file('image'), self::DIRECTORY)];

        if ($request->hasFile('bg_image')) {
            $data['bg_image'] = Media::store($request->file('bg_image'), self::DIRECTORY);
        }

        HeroContent::create($data);

        return back()->with('success', 'Slide added.');
    }

    public function update(HeroContentRequest $request, HeroContent $hero): RedirectResponse
    {
        $data = $request->hero();
        $oldFiles = [];

        if ($request->hasFile('image')) {
            $oldFiles[] = $hero->image;
            $data['image'] = Media::store($request->file('image'), self::DIRECTORY);
        }

        if ($request->hasFile('bg_image')) {
            $oldFiles[] = $hero->bg_image;
            $data['bg_image'] = Media::store($request->file('bg_image'), self::DIRECTORY);
        } elseif ($request->boolean('remove_bg_image')) {
            $oldFiles[] = $hero->bg_image;
            $data['bg_image'] = null;
        }

        $hero->update($data);
        array_map(Media::delete(...), $oldFiles); // only after the new ones are saved

        return back()->with('success', 'Slide updated.');
    }

    /** Put the slide in the slider, or take it out. */
    public function toggle(HeroContent $hero): RedirectResponse
    {
        $hero->update(['is_active' => ! $hero->is_active]);

        return back()->with('success', $hero->is_active ? 'Slide is now in the slider.' : 'Slide removed from the slider.');
    }

    public function destroy(HeroContent $hero): RedirectResponse
    {
        $hero->delete(); // the model also deletes its image files

        return back()->with('success', 'Slide deleted.');
    }
}
