<?php

namespace App\Http\Controllers\Admin\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ComposeEmailRequest;
use App\Jobs\SendComposedEmail;
use App\Models\Affiliate;
use App\Models\Instructor;
use App\Models\Student;
use App\Support\Media;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class EmailComposerController extends Controller
{
    public function create(): View
    {
        return view('admin.communication.email-compose', [
            'recipients' => [
                'student' => Student::query()->where('status', 'active')->whereNotNull('email')->orderBy('name')->get(['id', 'name', 'email']),
                'instructor' => Instructor::query()->where('status', 'active')->whereNotNull('email')->orderBy('name')->get(['id', 'name', 'email']),
                'affiliate' => Affiliate::query()->where('status', 'active')->whereNotNull('email')->orderBy('name')->get(['id', 'name', 'email']),
            ],
        ]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:2048'],
        ]);

        $path = Media::store($request->file('image'), 'email-images');

        return response()->json(['url' => Media::url($path)]);
    }

    public function send(ComposeEmailRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $model = match ($data['recipient_type']) {
            'instructor' => Instructor::class,
            'affiliate' => Affiliate::class,
            default => Student::class,
        };

        $userEmails = $model::query()
            ->whereIn('id', $data['user_ids'] ?? [])
            ->where('status', 'active')
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        $recipients = collect([...$userEmails, ...$request->customEmails()])
            ->map(fn($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return back()->withInput()->with('error', 'No valid email recipient was found.');
        }

        $subject = trim($data['subject']);
        $body = $this->cleanBody($data['body']);
        $attachment = $request->file('attachment');
        $attachmentPath = $attachment?->store('email-attachments', 'local');
        $attachmentName = $attachment?->getClientOriginalName();
        $attachmentMime = $attachment?->getMimeType();
        $adminId = $request->user()?->id;
        $recipientType = $data['recipient_type'];

        $jobs = $recipients->map(fn(string $email) => new SendComposedEmail(
            email: $email,
            subject: $subject,
            body: $body,
            attachmentPath: $attachmentPath,
            attachmentName: $attachmentName,
            attachmentMime: $attachmentMime,
            adminId: $adminId,
            recipientType: $recipientType,
        ))->all();

        try {
            Bus::batch($jobs)
                ->name('CreativeSacademy email: ' . $subject)
                ->finally(function (Batch $batch) use ($attachmentPath, $adminId, $subject, $recipients, $recipientType, $attachmentName) {
                    if ($attachmentPath) {
                        Storage::disk('local')->delete($attachmentPath);
                    }

                    Log::channel('app_file')->info('Email batch finished', [
                        'admin_id' => $adminId,
                        'recipient_type' => $recipientType,
                        'subject' => $subject,
                        'recipients' => $recipients->all(),
                        'total' => $batch->totalJobs,
                        'failed' => $batch->failedJobs,
                        'attachment' => $attachmentName,
                    ]);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            if ($attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }

            Log::channel('app_file')->error('Email batch could not be queued', [
                'admin_id' => $adminId,
                'recipient_type' => $recipientType,
                'subject' => $subject,
                'recipients' => $recipients->all(),
                'attachment' => $attachmentName,
                'error' => $exception->getMessage(),
            ]);

            return back()->withInput()->with('error', 'The email could not be queued. Check your queue configuration.');
        }

        Log::channel('app_file')->info('Email batch queued', [
            'admin_id' => $adminId,
            'recipient_type' => $recipientType,
            'subject' => $subject,
            'recipients' => $recipients->all(),
            'total' => $recipients->count(),
            'attachment' => $attachmentName,
        ]);
        Log::channel('app_file')->info('Email batch queued', [
            'admin_id' => $adminId,
            'recipient_type' => $recipientType,
            'subject' => $subject,
            'recipients' => $recipients->all(),
            'total' => $recipients->count(),
            'attachment' => $attachmentName,
        ]);

        return back()->with('success', "Email queued for {$recipients->count()} recipient(s). Keep the queue worker running to deliver them.");
    }

    private function cleanBody(string $body): string
    {
        return trim(strip_tags($body, '<p><br><strong><b><em><i><u><s><sub><sup><ul><ol><li><blockquote><h1><h2><h3><h4><a><div><span><table><thead><tbody><tfoot><tr><th><td><img><hr><font>'));
    }
}
