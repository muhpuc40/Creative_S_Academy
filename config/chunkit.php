<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAdminIsActive;

/*
 * Creative S Academy: course videos/files are uploaded by admins only, in 10 MiB chunks,
 * and kept on the PRIVATE "local" disk (storage/app/private) - never storage/app/public.
 * CourseContentController moves a finished upload to course-content/{content_id}/.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Chunk size
    |--------------------------------------------------------------------------
    |
    | Size in bytes of each chunk the browser is told to send (default 5 MiB).
    | It is returned by POST /initiate and saved on the upload row, so changing
    | this later never breaks uploads that are already in flight.
    |
    | Every chunk is one normal PHP file upload, so this MUST stay below both
    | upload_max_filesize and post_max_size in php.ini, or PHP rejects the
    | request before Laravel sees it.
    |
    */

    'chunk_size' => (int) env('CHUNKIT_CHUNK_SIZE', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Max chunk size
    |--------------------------------------------------------------------------
    |
    | Hard cap in bytes for a single chunk request (default 10 MiB). A chunk
    | larger than this is rejected with 422, whatever the client claims.
    |
    */

    'max_chunk_size' => (int) env('CHUNKIT_MAX_CHUNK_SIZE', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Max total size
    |--------------------------------------------------------------------------
    |
    | Largest complete file in bytes that /initiate will accept (default 5 GiB).
    | This is what protects the disk, so set it to what you actually need.
    |
    */

    'max_total_size' => (int) env('CHUNKIT_MAX_TOTAL_SIZE', 2 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME types
    |--------------------------------------------------------------------------
    |
    | Creative S Academy: every type. Chunkit only receives the file onto the
    | private disk; the app checks it when the file is attached:
    |   - lesson content (CourseContentController): the real type must be a
    |     video (MP4, WEBM, MOV, MKV) or a PDF;
    |   - resources (CourseResourceController): System > File types
    |     (extension and size), because formats like .zprj or .emb have no
    |     MIME type that could be listed here.
    |
    */

    'allowed_mimes' => [
        'application/*', 'audio/*', 'chemical/*', 'font/*', 'image/*', 'inode/*',
        'message/*', 'model/*', 'multipart/*', 'text/*', 'video/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked extensions
    |--------------------------------------------------------------------------
    |
    | Creative S Academy: THE never-allowed list for every upload. Chunkit
    | refuses these names itself, and App\Models\FileType::blocked() uses the
    | same list for System > File types, assignment files and email
    | attachments. Lowercase, without the dot. Every part of a name is
    | checked, so "shell.php.zip" is refused along with "shell.php".
    | These could run on the server (scripts) or in the browser (web pages).
    |
    */

    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'inc',
        'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp',
        'sh', 'bash', 'bat', 'cmd', 'ps1', 'dll', 'com',
        'html', 'htm', 'xhtml', 'shtml', 'js', 'mjs',
        'htaccess', 'htpasswd',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload lifetime (hours)
    |--------------------------------------------------------------------------
    |
    | How long an unfinished upload is kept after its last activity before
    | `php artisan chunkit:prune` may delete its row and its chunks.
    |
    */

    'ttl_hours' => (int) env('CHUNKIT_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Chunk storage
    |--------------------------------------------------------------------------
    |
    | chunk_disk is the disk that holds chunks while an upload is running and
    | chunk_directory is the folder on that disk. v1 supports local-driver
    | disks only. With Laravel 11+'s default "local" disk the chunks end up in
    | storage/app/private/{chunk_directory}/{uploadId}/{index}.
    |
    */

    'chunk_disk' => env('CHUNKIT_CHUNK_DISK', 'local'),

    'chunk_directory' => 'chunkit',

    /*
    |--------------------------------------------------------------------------
    | Final storage
    |--------------------------------------------------------------------------
    |
    | final_disk is where the merged file is written (v1: local-driver disks
    | only) and final_path_pattern is its path on that disk. Supported
    | placeholders: {uuid} (the upload id) and {original_name} (sanitised).
    | The default puts a finished file at storage/app/public/uploads/{uuid}/{name}.
    |
    */

    'final_disk' => env('CHUNKIT_FINAL_DISK', 'local'),

    'final_path_pattern' => 'chunkit-done/{uuid}/{original_name}',

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The merge runs as a queued job. Leave queue_connection and queue_name
    | null to use the host app's default queue worker; only set these if you
    | run a dedicated worker.
    |
    */

    'queue_connection' => null,

    'queue_name' => null,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | route_prefix is the URL prefix for every endpoint (default /chunkit).
    | middleware is applied to all of them. The default ['web'] keeps sessions
    | and CSRF protection, which Blade pages need. Add 'auth' here (for example
    | ['web', 'auth']) to restrict who may upload.
    |
    */

    'route_prefix' => env('CHUNKIT_ROUTE_PREFIX', 'chunkit'),

    'middleware' => ['web', 'auth', EnsureAdminIsActive::class],

    /*
    |--------------------------------------------------------------------------
    | Chunk locking
    |--------------------------------------------------------------------------
    |
    | Every chunk write runs inside Cache::lock("chunkit:{uploadId}").
    | lock_seconds is how long a lock may be held before it expires on its own
    | (a safety net for crashed requests). lock_wait_seconds is how long a
    | request waits for the lock before it answers 409.
    |
    | Locks need a cache store that supports them: file, database, redis,
    | memcached, dynamodb and array do. Redis is NOT required.
    |
    */

    'lock_seconds' => (int) env('CHUNKIT_LOCK_SECONDS', 30),

    'lock_wait_seconds' => (int) env('CHUNKIT_LOCK_WAIT_SECONDS', 10),

];
