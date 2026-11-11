<?php

namespace App\Http\Controllers\Admin\Course;

use App\Http\Controllers\Controller;
use App\Models\CourseResource;
use App\Models\FileType;
use App\Support\FinishedUpload;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /courses/upload-resource
 * The resource library: files and links students can use (slides, PDFs, zips ...).
 * One resource can be used by many module items.
 *
 * Files are uploaded in chunks to chunkit (/chunkit/...); the form sends the finished
 * upload's id (chunkit_upload_id). Its name and size must pass System > File types,
 * then it moves to the PRIVATE disk (App\Support\PrivateFile). A link can be pasted instead.
 */
class CourseResourceController extends Controller
{
    public function index(): View
    {
        return view('admin.library.resources', [
            'resources' => CourseResource::withCount('items')->latest('id')->paginate(15),
            'limits' => FileType::limits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);

        DB::transaction(fn () => CourseResource::create([
            'name' => $data['name'],
            'url' => $data['chunkit_upload_id'] ? $this->publish($data['chunkit_upload_id']) : $data['url'],
        ]));

        return back()->with('success', 'Resource saved.');
    }

    public function update(Request $request, CourseResource $resource): RedirectResponse
    {
        $data = $this->validated($request, false);
        $old = $resource->url;
        $changes = ['name' => $data['name']];

        DB::transaction(function () use ($data, $resource, &$changes) {
            if ($data['chunkit_upload_id']) {
                $changes['url'] = $this->publish($data['chunkit_upload_id']);
            } elseif (! empty($data['url']) && $data['url'] !== $resource->url) {
                $changes['url'] = $data['url'];
            }
            // Neither given: keep the current file / link.

            $resource->update($changes);
        });

        if (isset($changes['url'])) {
            PrivateFile::delete($old); // a new file or link replaced it
        }

        return back()->with('success', 'Resource updated.');
    }

    public function destroy(CourseResource $resource): RedirectResponse
    {
        if ($resource->items()->exists()) {
            return back()->with('error', "This resource is used by {$resource->items()->count()} item(s). Remove it from them first.");
        }

        PrivateFile::delete($resource->url);
        $resource->delete();

        return back()->with('success', 'Resource deleted.');
    }

    /** GET .../{resource}/file: opens the uploaded file (admins only). */
    public function file(CourseResource $resource): BinaryFileResponse
    {
        return PrivateFile::response($resource->url, $resource->name);
    }

    /** A finished chunkit upload that passes System > File types, stored privately. Returns its path. */
    private function publish(string $uploadId): string
    {
        $file = FinishedUpload::take($uploadId);
        $file->checkFileType();

        return $file->storeIn('course-resources');
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // a finished chunkit upload OR a link
            'chunkit_upload_id' => [$creating ? 'required_without:url' : 'nullable', 'nullable', 'uuid'],
            'url' => [$creating ? 'required_without:chunkit_upload_id' : 'nullable', 'nullable', 'string', 'max:2048'],
        ], [
            'url.required_without' => 'Upload a file or paste a link.',
            'chunkit_upload_id.required_without' => 'Upload a file or paste a link.',
        ]) + ['url' => null, 'chunkit_upload_id' => null];
    }
}
