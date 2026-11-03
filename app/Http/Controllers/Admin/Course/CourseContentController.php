<?php

namespace App\Http\Controllers\Admin\Course;

use App\Http\Controllers\Controller;
use App\Models\CourseContent;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

/**
 * GET /courses/upload-content
 * The content library: what a class / exam / assignment shows (video, file ...).
 * One content can be used by many module items.
 *
 * Files (up to 2 GiB) are uploaded in chunks by the browser straight to chunkit
 * (/chunkit/...). The form then only sends the finished upload's id
 * (chunkit_upload_id); this controller moves that file to the content's own
 * private folder. A link can be pasted instead of a file.
 */
class CourseContentController extends Controller
{
    public function index(): View
    {
        return view('admin.library.content', [
            'contents' => CourseContent::withCount('items')->latest('id')->paginate(15),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = Media::store($request->file('thumbnail'), 'content-thumbnails');
        }

        DB::transaction(function () use ($data) {
            $content = CourseContent::create([
                'name' => $data['name'],
                'thumbnail' => $data['thumbnail'] ?? null,
                'url' => $data['chunkit_upload_id'] ? null : $data['url'],
            ]);

            if ($data['chunkit_upload_id']) {
                $this->attachUpload($content, $data['chunkit_upload_id']);
            }
        });

        return back()->with('success', 'Content saved.');
    }

    public function update(Request $request, CourseContent $content): RedirectResponse
    {
        $data = $this->validated($request, false);
        $changes = ['name' => $data['name']];

        if ($request->hasFile('thumbnail')) {
            Media::delete($content->thumbnail);
            $changes['thumbnail'] = Media::store($request->file('thumbnail'), 'content-thumbnails');
        }

        DB::transaction(function () use ($data, $content, $changes) {
            if ($data['chunkit_upload_id']) {
                // A new file replaces whatever was there. attachUpload() empties the
                // content's private folder first; an old public-disk file goes too.
                Media::delete($content->url);
                $this->attachUpload($content, $data['chunkit_upload_id']);
                $changes['url'] = null;
            } elseif (! empty($data['url']) && $data['url'] !== $content->url) {
                // A link replaces whatever was there.
                Media::delete($content->url);
                $this->deletePrivateFile($content);
                $changes += ['url' => $data['url'], 'file_disk' => null, 'file_path' => null,
                    'file_name' => null, 'file_size' => null, 'file_mime' => null];
            }
            // Neither given: keep the current file / link.

            $content->update($changes);
        });

        return back()->with('success', 'Content updated.');
    }

    public function destroy(CourseContent $content): RedirectResponse
    {
        if ($content->items()->exists()) {
            return back()->with('error', "This content is used by {$content->items()->count()} item(s). Remove it from them first.");
        }

        Media::delete($content->thumbnail);
        Media::delete($content->url);
        $this->deletePrivateFile($content);
        $content->delete();

        return back()->with('success', 'Content deleted.');
    }

    /**
     * Takes a finished chunkit upload and makes it this content's file: moves it into the
     * content's private folder, records name/size/real type, and deletes the upload row so
     * the same upload id can never be attached twice.
     */
    private function attachUpload(CourseContent $content, string $uploadId): void
    {
        /** @var ChunkedUpload|null $upload */
        $upload = ChunkedUpload::where('upload_id', $uploadId)->lockForUpdate()->first();

        if (! $upload || $upload->status !== ChunkedUpload::STATUS_COMPLETED || ! $upload->destination_path) {
            throw ValidationException::withMessages([
                'chunkit_upload_id' => 'The uploaded file is not ready yet, or was already used. Upload it again.',
            ]);
        }

        $disk = config('chunkit.final_disk');
        $storage = Storage::disk($disk);

        if (! $storage->exists($upload->destination_path)) {
            throw ValidationException::withMessages([
                'chunkit_upload_id' => 'The uploaded file could not be found. Upload it again.',
            ]);
        }

        $target = $content->storageDirectory().'/'.basename($upload->destination_path);
        $storage->deleteDirectory($content->storageDirectory()); // a replaced file goes away
        $storage->move($upload->destination_path, $target);
        $storage->deleteDirectory(dirname($upload->destination_path)); // the now-empty chunkit-done/{uuid}

        $content->forceFill([
            'file_disk' => $disk,
            'file_path' => $target,
            'file_name' => $upload->original_name,
            'file_size' => $upload->total_bytes,
            'file_mime' => $storage->mimeType($target) ?: null, // the real type, not what the browser claimed
        ])->save();

        $upload->delete();
    }

    /** Removes the content's private folder (its uploaded file), if it has one. */
    private function deletePrivateFile(CourseContent $content): void
    {
        if ($content->hasStoredFile()) {
            Storage::disk($content->file_disk)->deleteDirectory($content->storageDirectory());
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'image', 'max:2048'],
            // a finished chunkit upload OR a link
            'chunkit_upload_id' => [$creating ? 'required_without:url' : 'nullable', 'nullable', 'uuid'],
            'url' => [$creating ? 'required_without:chunkit_upload_id' : 'nullable', 'nullable', 'url', 'max:2048'],
        ], [
            'url.required_without' => 'Upload a file or paste a link.',
            'chunkit_upload_id.required_without' => 'Upload a file or paste a link.',
        ]) + ['url' => null, 'chunkit_upload_id' => null];
    }
}
