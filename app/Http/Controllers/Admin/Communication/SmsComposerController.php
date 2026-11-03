<?php

namespace App\Http\Controllers\Admin\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ComposeSmsRequest;
use App\Jobs\SendQuickSms;
use App\Models\Affiliate;
use App\Models\Instructor;
use App\Models\Student;
use Illuminate\Bus\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SmsComposerController extends Controller
{
    public function create(): View
    {
        return view('admin.communication.sms-compose', [
            'recipients' => [
                'student' => Student::query()->where('status', 'active')->whereNotNull('phone')->orderBy('name')->get(['id', 'name', 'phone']),
                'instructor' => Instructor::query()->where('status', 'active')->whereNotNull('phone')->orderBy('name')->get(['id', 'name', 'phone']),
                'affiliate' => Affiliate::query()->where('status', 'active')->whereNotNull('phone')->orderBy('name')->get(['id', 'name', 'phone']),
            ],
            'rate' => config('messaging.sms.rate'),
        ]);
    }

    public function send(ComposeSmsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $model = match ($data['recipient_type']) {
            'instructor' => Instructor::class,
            'affiliate' => Affiliate::class,
            default => Student::class,
        };

        $userNumbers = $model::query()
            ->whereIn('id', $data['user_ids'] ?? [])
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->pluck('phone')
            ->all();

        $numbers = collect([...$userNumbers, ...$request->customNumbers()])
            ->map(fn($n) => preg_replace('/\D+/', '', (string) $n))
            ->map(fn($n) => str_starts_with($n, '01') ? '88' . $n : $n)
            ->filter(fn($n) => preg_match('/^8801[3-9]\d{8}$/', $n))
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return back()->withInput()->with('error', 'No valid phone number was found.');
        }

        $message = trim($data['message']);
        $adminId = $request->user()?->id;
        $recipientType = $data['recipient_type'];
        $chunkSize = max(1, (int) config('messaging.sms.bulk_chunk', 100));

        $jobs = $numbers->chunk($chunkSize)
            ->map(fn($chunk) => new SendQuickSms(
                numbers: $chunk->values()->all(),
                message: $message,
                adminId: $adminId,
                recipientType: $recipientType,
            ))->all();

        try {
            Bus::batch($jobs)
                ->name('CreativeSacademy quick SMS: ' . $recipientType)
                ->finally(function (Batch $batch) use ($adminId, $recipientType, $numbers, $message) {
                    Log::channel('app_file')->info('SMS batch finished', [
                        'admin_id' => $adminId,
                        'recipient_type' => $recipientType,
                        'total_numbers' => $numbers->count(),
                        'failed_chunks' => $batch->failedJobs,
                        'message' => $message,
                    ]);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            Log::channel('app_file')->error('SMS batch could not be queued', [
                'admin_id' => $adminId,
                'recipient_type' => $recipientType,
                'total_numbers' => $numbers->count(),
                'error' => $exception->getMessage(),
            ]);

            return back()->withInput()->with('error', 'The SMS could not be queued. Check your queue configuration.');
        }

        Log::channel('app_file')->info('SMS batch queued', [
            'admin_id' => $adminId,
            'recipient_type' => $recipientType,
            'total_numbers' => $numbers->count(),
        ]);

        return back()->with('success', "SMS queued for {$numbers->count()} number(s). Keep the queue worker running to deliver them.");
    }
}