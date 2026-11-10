<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\SmsSetting;
use App\Services\Messaging\Senders\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** System > SMS: the SMS gateway settings, saved in the database (no redeploy to change them). */
class SmsSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.system.sms', ['settings' => SmsSetting::current()->load('updatedBy:id,name')]);
    }

    /** An empty API key keeps the saved one. */
    public function update(Request $request): RedirectResponse
    {
        $settings = SmsSetting::current();
        $sends = $request->input('driver') !== 'log';

        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys(SmsSetting::DRIVERS))],
            'url' => ['required', 'url', 'max:255'],
            'api_key' => [$sends && ! $settings->api_key ? 'required' : 'nullable', 'string', 'max:255'],
            'sender_id' => [$sends ? 'required' : 'nullable', 'string', 'max:50'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'bulk_chunk' => ['required', 'integer', 'min:1', 'max:1000'],
        ], [
            'api_key.required' => 'The API key is needed to send real SMS.',
            'sender_id.required' => 'The sender ID is needed to send real SMS.',
        ]);

        $data['api_key'] = trim((string) $data['api_key']) ?: $settings->api_key;
        $data['sender_id'] = trim((string) $data['sender_id']) ?: null;
        $data['updated_by'] = $request->user()->id;

        $settings->update($data);

        return back()->with('success', 'SMS settings saved.');
    }

    /** GET from the page (after it opens): the gateway balance as JSON. */
    public function balance(SmsSender $sms): JsonResponse
    {
        try {
            $balance = $sms->balance();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rate = SmsSetting::current()->rate;

        return response()->json([
            'balance' => number_format($balance, 2),
            'sms' => $rate > 0 ? number_format(floor($balance / $rate)) : null,
            'low' => $balance < 100,
        ]);
    }
}
