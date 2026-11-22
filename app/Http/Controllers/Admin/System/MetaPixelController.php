<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\MetaPixelSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System > Meta Pixel: the pixel the student website loads (sent in GET /api/v1/home).
 * Saved in the database, so it can change at any time. Switched off = no pixel on the website.
 */
class MetaPixelController extends Controller
{
    public function edit(): View
    {
        return view('admin.system.meta-pixel', ['settings' => MetaPixelSetting::current()->load('updatedBy:id,name')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Meta pixel IDs are only digits (usually 15-16); the website accepts 6-20
            'pixel_id' => ['nullable', 'string', 'regex:/^\d{6,20}$/'],
            'is_active' => ['required', 'boolean'],
        ], [
            'pixel_id.regex' => 'A pixel ID is only digits, e.g. 1234567890123456 (Events Manager > Data sources).',
        ]);

        $pixelId = $data['pixel_id'] ?? null;
        if ($data['is_active'] && ! $pixelId) {
            return back()->withInput()->withErrors(['pixel_id' => 'Enter the pixel ID to switch the pixel on.']);
        }

        MetaPixelSetting::current()->update([
            'pixel_id' => $pixelId,
            'is_active' => (bool) $data['is_active'],
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Meta Pixel saved. The website picks it up within a few minutes.');
    }
}
