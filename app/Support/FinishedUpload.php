<?php

namespace App\Support;

use App\Models\FileType;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

/**
 * A finished chunkit upload (on chunkit's private disk) that a form sent as chunkit_upload_id.
 * Call inside a DB transaction: the row is locked, so one upload can't be attached twice.
 */
class FinishedUpload
{
    private function __construct(public readonly ChunkedUpload $upload, public readonly FilesystemAdapter $disk) {}

    public static function take(string $uploadId): self
    {
        $upload = ChunkedUpload::where('upload_id', $uploadId)->lockForUpdate()->first();

        if (! $upload || $upload->status !== ChunkedUpload::STATUS_COMPLETED || ! $upload->destination_path) {
            throw self::error('The uploaded file is not ready yet, or was already used. Upload it again.');
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('chunkit.final_disk'));
        $file = new self($upload, $disk);

        if (! $file->disk->exists($upload->destination_path)) {
            throw self::error('The uploaded file could not be found. Upload it again.');
        }

        return $file;
    }

    public function path(): string
    {
        return $this->upload->destination_path;
    }

    /** The real type, from the file's bytes (not what the browser claimed). */
    public function mime(): string
    {
        return (string) $this->disk->mimeType($this->path());
    }

    /** Refuses the file unless its name and size pass System > File types. */
    public function checkFileType(): void
    {
        if ($problem = FileType::problem($this->upload->original_name, (int) $this->upload->total_bytes)) {
            $this->reject($problem);
        }
    }

    /**
     * Moves the file to the private disk as $directory/{random}.{ext} and forgets the upload.
     * Returns the new path (for PrivateFile).
     */
    public function storeIn(string $directory): string
    {
        $ext = strtolower(pathinfo($this->upload->original_name, PATHINFO_EXTENSION));
        $path = $directory.'/'.Str::random(40).($ext !== '' ? ".{$ext}" : '');

        if (config('chunkit.final_disk') === PrivateFile::DISK) {
            $this->disk->move($this->path(), $path); // same disk: a rename, even for a 2 GB file
        } else {
            $stream = $this->disk->readStream($this->path());
            Storage::disk(PrivateFile::DISK)->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
        }

        $this->forget();

        return $path;
    }

    /**
     * Refuse the file: delete it, then fail the form with $message.
     * (The row stays, the transaction rolls back; the hourly chunkit:forget-unsaved-uploads job removes it.)
     */
    public function reject(string $message): never
    {
        $this->disk->deleteDirectory(dirname($this->path()));

        throw self::error($message);
    }

    /** Remove the leftover chunkit-done/{uuid} folder and the upload row (after the file was moved, or to drop it). */
    public function forget(): void
    {
        $this->disk->deleteDirectory(dirname($this->path()));
        $this->upload->delete();
    }

    private static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['chunkit_upload_id' => $message]);
    }
}
