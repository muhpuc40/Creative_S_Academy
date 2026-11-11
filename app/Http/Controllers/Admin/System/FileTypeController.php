<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\FileType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** System > File types: the formats admins may upload, with a size limit per group. */
class FileTypeController extends Controller
{
    public function index(): View
    {
        return view('admin.system.file-types', [
            'types' => FileType::orderBy('sort_order')->orderBy('id')->get(),
            'maxMb' => self::maxMb(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        FileType::create($this->validated($request) + ['sort_order' => (int) FileType::max('sort_order') + 1]);

        return back()->with('success', 'File type added.');
    }

    public function update(Request $request, FileType $fileType): RedirectResponse
    {
        $fileType->update($this->validated($request, $fileType));

        return back()->with('success', 'File type saved.');
    }

    public function destroy(FileType $fileType): RedirectResponse
    {
        $fileType->delete();

        return back()->with('success', 'File type deleted.');
    }

    /** The largest file chunkit takes (config chunkit.max_total_size), in MB. */
    private static function maxMb(): int
    {
        return intdiv((int) config('chunkit.max_total_size'), 1024 * 1024);
    }

    private function validated(Request $request, ?FileType $fileType = null): array
    {
        $request->merge(['extensions' => FileType::parse($request->input('extensions'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('file_types')->ignore($fileType)],
            'extensions' => ['required', 'array', 'max:100'],
            'extensions.*' => ['string', 'max:20', Rule::notIn(FileType::blocked())],
            'max_size_mb' => ['required', 'integer', 'min:1', 'max:'.self::maxMb()],
        ], [
            'extensions.required' => 'Add at least one extension.',
            'extensions.*.not_in' => '.:input files can never be allowed: they could run on the server or in the browser.',
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
