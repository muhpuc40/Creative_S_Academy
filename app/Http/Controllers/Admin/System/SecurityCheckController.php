<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\QuarantinedFile;
use App\Support\Security\SecurityScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * System > Security Check: a Windows-style on-demand scan of the uploaded files
 * plus a battery of configuration/storage/upload health checks. The page drives
 * the scan from a modal that streams progress and findings; threats are moved to
 * quarantine, from where they can be restored or deleted.
 */
class SecurityCheckController extends Controller
{
    public function index(SecurityScanner $scanner): View
    {
        return view('admin.system.security-check', [
            'health' => SecurityScanner::HEALTH,
            'quarantined' => QuarantinedFile::latest()->get(),
        ]);
    }

    /** Snapshot the file list and return a scan token + total count. */
    public function start(SecurityScanner $scanner): JsonResponse
    {
        return response()->json($scanner->startFileScan());
    }

    /** Inspect one batch of files; quarantine threats; report progress + findings. */
    public function files(Request $request, SecurityScanner $scanner): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'uuid'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($scanner->scanBatch($data['token'], (int) $data['offset']));
    }

    /** Run one health check by id. */
    public function check(Request $request, SecurityScanner $scanner, string $check): JsonResponse
    {
        $request->merge(['check' => $check])->validate([
            'check' => ['required', Rule::in(array_column(SecurityScanner::HEALTH, 'id'))],
        ]);

        return response()->json($scanner->check($check));
    }

    /** Put a quarantined file back where it came from. */
    public function restore(SecurityScanner $scanner, QuarantinedFile $quarantine): JsonResponse
    {
        $error = $scanner->restore($quarantine);

        return response()->json(['ok' => $error === null, 'error' => $error]);
    }

    /** Delete a quarantined file for good. */
    public function destroy(SecurityScanner $scanner, QuarantinedFile $quarantine): JsonResponse
    {
        $scanner->remove($quarantine);

        return response()->json(['ok' => true]);
    }
}
