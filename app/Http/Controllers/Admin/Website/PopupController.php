<?php

namespace App\Http\Controllers\Admin\Website;

use App\Enums\PopupKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PopupRequest;
use App\Models\Popup;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Website > Popups: what each popup shows (popups) and when and where (popup_settings). */
class PopupController extends Controller
{
    private const DIRECTORY = 'popups';

    public function index(): View
    {
        return view('admin.website.popups.index', [
            // modals first, then corners; each by its order
            'popups' => Popup::with('setting')->ordered()->get()->sortBy(fn (Popup $p) => $p->kind === PopupKind::Corner)->values(),
        ]);
    }

    public function store(PopupRequest $request): RedirectResponse
    {
        $data = $request->content();
        if ($request->hasFile('image')) {
            $data['image'] = Media::store($request->file('image'), self::DIRECTORY);
        }

        DB::transaction(function () use ($data, $request) {
            Popup::create($data)->setting()->create($request->settings());
        });

        return back()->with('success', 'Popup added.');
    }

    public function update(PopupRequest $request, Popup $popup): RedirectResponse
    {
        $data = $request->content();
        $oldImage = null;

        if ($request->hasFile('image')) {
            $oldImage = $popup->image;
            $data['image'] = Media::store($request->file('image'), self::DIRECTORY);
        } elseif ($request->boolean('remove_image') && $request->kind()->hasText()) {
            $oldImage = $popup->image; // a corner popup cannot lose its image
            $data['image'] = null;
        }

        DB::transaction(function () use ($popup, $data, $request) {
            $popup->update($data);
            $popup->setting()->updateOrCreate([], $request->settings());
        });
        Media::delete($oldImage); // only after the new one is saved

        return back()->with('success', 'Popup updated.');
    }

    /** Turn the popup on or off. */
    public function toggle(Popup $popup): RedirectResponse
    {
        $popup->setting->update(['is_active' => ! $popup->setting->is_active]);

        return back()->with('success', $popup->setting->is_active ? 'Popup is on.' : 'Popup is off.');
    }

    public function destroy(Popup $popup): RedirectResponse
    {
        $popup->delete(); // its setting goes with it; the model deletes the image

        return back()->with('success', 'Popup deleted.');
    }
}
