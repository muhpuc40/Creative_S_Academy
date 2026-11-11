<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Check (System > Security Check)
    |--------------------------------------------------------------------------
    |
    | Settings for the on-demand security scan. The scan looks only through the
    | uploaded-file folders below (the real upload attack surface), flags any
    | forbidden or script-like file, and moves (quarantines) it to a locked,
    | non-public folder so it can be restored or deleted by hand.
    |
    */

    // The folders the file scan walks. Uploaded files only — never code/vendor.
    'scan_paths' => [
        storage_path('app/private'),
        storage_path('app/public'),
    ],

    // Quarantined files are moved here. Sits under storage/app (never web-served)
    // and outside scan_paths, so the scan never re-scans quarantined files.
    'quarantine_path' => storage_path('app/quarantine'),

    // Scratch folder for one scan's file list, so progress is consistent across
    // batches even while threats are being moved. Also outside scan_paths.
    'work_path' => storage_path('app/security-scans'),

    // How many files one batch inspects (keeps each request short, feeds the bar).
    'batch_size' => (int) env('SECURITY_SCAN_BATCH', 200),

    // The head of each file (bytes) read to sniff for embedded code.
    'sniff_bytes' => 8192,

    // Case-insensitive markers that mean "runnable code is inside this upload",
    // whatever the extension says. A .jpg holding any of these is a web shell.
    'code_markers' => [
        '<?php',
        '<?=',
        '<script',
        '#!/',            // shebang: #!/bin/sh, #!/usr/bin/env ...
    ],
];
