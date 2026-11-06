<?php

namespace App\Http\Controllers\Admin\Website;

use App\Enums\DisplayAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HeroSettingRequest;
use App\Models\HeroContent;
use App\Models\HeroSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Website > Hero settings: what the home page hero shows and how the slider behaves.
 * The page is built from App\Enums\DisplayAction, so a new action shows up here by itself.
 */
class HeroSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.website.hero-settings.edit', [
            'settings' => HeroSetting::current(),
            'groups' => HeroSettingRequest::groups(),
            'standalone' => array_values(array_filter(DisplayAction::cases(), fn (DisplayAction $a) => $a->group() === null)),
            'activeSlides' => HeroContent::active()->count(),
        ]);
    }

    public function update(HeroSettingRequest $request): RedirectResponse
    {
        HeroSetting::saveMany($request->settings());

        return back()->with('success', 'Hero settings saved. The website updates within a few minutes.');
    }
}
